<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphTransfer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class GraphTransferTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    private function transfer(): GraphTransfer
    {
        return $this->app->make(GraphTransfer::class);
    }

    private function repository(): DefinitionRepository
    {
        return $this->app->make(DefinitionRepository::class);
    }

    private function graph(string $nodeId = 'start'): GraphDefinition
    {
        return new GraphDefinition([new GraphNode($nodeId, 'test.greet', ['name' => 'Ada'])], []);
    }

    public function test_export_emits_pretty_json_envelope_with_a_definition_provenance_block(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $published = $repository->publish('greeter', $stored->version);

        $json = $this->transfer()->export($published);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString("\n", $json);
        $this->assertSame('laravel-flow', $decoded['kind']);
        $this->assertSame('greeter', $decoded['definition']['name']);
        $this->assertSame($published->version, $decoded['definition']['version']);
        $this->assertSame(StoredDefinition::STATUS_PUBLISHED, $decoded['definition']['status']);
        $this->assertSame($published->checksum, $decoded['definition']['checksum']);
        $this->assertSame(['start'], array_column($decoded['nodes'], 'id'));
    }

    public function test_export_accepts_a_draft_stored_definition_too(): void
    {
        $this->migrateFlowTables();
        $draft = $this->repository()->createDraft('greeter', $this->graph());

        $json = $this->transfer()->export($draft);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(StoredDefinition::STATUS_DRAFT, $decoded['definition']['status']);
    }

    public function test_export_then_import_under_a_new_name_round_trips_the_checksum(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $published = $repository->publish('greeter', $stored->version);

        $json = $this->transfer()->export($published);
        $imported = $this->transfer()->importDraft($json, 'greeter-copy');

        $this->assertSame('greeter-copy', $imported->name);
        $this->assertSame(1, $imported->version);
        $this->assertSame(StoredDefinition::STATUS_DRAFT, $imported->status);
        $this->assertSame($published->checksum, $imported->checksum);
        $this->assertNotSame($published->name, $imported->name);
    }

    public function test_import_draft_rejects_a_graph_that_fails_semantic_validation(): void
    {
        $this->migrateFlowTables();

        $invalidJson = json_encode([
            'schema_version' => 1,
            'kind' => 'laravel-flow',
            'metadata' => [],
            'nodes' => [['id' => 'start', 'type' => 'nonexistent.node.type', 'config' => [], 'position' => null]],
            'connections' => [],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->transfer()->importDraft($invalidJson, 'broken-import');
            $this->fail('Expected InvalidGraphException.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('Unknown node type [nonexistent.node.type]', $e->getMessage());
        }

        $this->assertNull($this->repository()->latest('broken-import'));
    }

    public function test_import_draft_rejects_malformed_json(): void
    {
        $this->migrateFlowTables();

        $this->expectException(InvalidGraphException::class);

        $this->transfer()->importDraft('{not-json', 'broken-import');
    }

    public function test_import_draft_ignores_the_definition_provenance_block(): void
    {
        // A hand-authored payload could plausibly include a `definition`
        // key even without going through export() first; it must be
        // stripped rather than tripping GraphSerializer on an unexpected
        // envelope shape.
        $this->migrateFlowTables();

        $json = json_encode([
            'schema_version' => 1,
            'kind' => 'laravel-flow',
            'metadata' => [],
            'nodes' => [['id' => 'start', 'type' => 'test.greet', 'config' => ['name' => 'Ada'], 'position' => null]],
            'connections' => [],
            'definition' => ['name' => 'ignored-source-name', 'version' => 99, 'status' => 'archived', 'checksum' => 'stale'],
        ], JSON_THROW_ON_ERROR);

        $imported = $this->transfer()->importDraft($json, 'fresh-name');

        $this->assertSame('fresh-name', $imported->name);
        $this->assertSame(1, $imported->version);
    }
}
