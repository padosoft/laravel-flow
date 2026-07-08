<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;

final class DefinitionTransferCommandTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    private function repository(): DefinitionRepository
    {
        return $this->app->make(DefinitionRepository::class);
    }

    private function graph(string $nodeId = 'start'): GraphDefinition
    {
        return new GraphDefinition([new GraphNode($nodeId, 'test.greet', ['name' => 'Ada'])], []);
    }

    private function tempPath(string $suffix = '.json'): string
    {
        $path = sys_get_temp_dir().'/flow-transfer-'.uniqid('', true).$suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_export_command_writes_pretty_json_to_stdout_by_default(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $repository->publish('greeter', $stored->version);

        $this->artisan('flow:export', ['name' => 'greeter'])
            ->expectsOutputToContain('"kind": "laravel-flow"')
            ->assertExitCode(0);
    }

    public function test_export_command_writes_to_a_file_with_the_file_option(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $repository->publish('greeter', $stored->version);
        $path = $this->tempPath();

        $this->artisan('flow:export', ['name' => 'greeter', '--file' => $path])
            ->expectsOutputToContain(sprintf('Exported flow definition [greeter] version [%d] to', $stored->version))
            ->assertExitCode(0);

        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('greeter', $decoded['definition']['name']);
    }

    public function test_export_command_can_target_an_explicit_version(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $v1 = $repository->createDraft('greeter', $this->graph());
        $repository->publish('greeter', $v1->version);
        $repository->createDraft('greeter', $this->graph('second'));

        $this->artisan('flow:export', ['name' => 'greeter', '--definition-version' => (string) $v1->version])
            ->expectsOutputToContain('"kind": "laravel-flow"')
            ->assertExitCode(0);
    }

    public function test_export_command_fails_when_no_published_version_exists(): void
    {
        $this->migrateFlowTables();
        $this->repository()->createDraft('greeter', $this->graph());

        $this->artisan('flow:export', ['name' => 'greeter'])
            ->expectsOutputToContain('has no published version to export')
            ->assertExitCode(1);
    }

    public function test_export_command_fails_for_an_unknown_explicit_version(): void
    {
        $this->migrateFlowTables();

        $this->artisan('flow:export', ['name' => 'missing', '--definition-version' => '1'])
            ->expectsOutputToContain('was not found')
            ->assertExitCode(1);
    }

    public function test_export_command_rejects_a_non_numeric_version_option(): void
    {
        $this->migrateFlowTables();

        $this->artisan('flow:export', ['name' => 'greeter', '--definition-version' => 'abc'])
            ->expectsOutputToContain('--definition-version must be a positive integer')
            ->assertExitCode(1);
    }

    public function test_export_then_import_round_trip_creates_a_new_named_draft_with_matching_checksum(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $published = $repository->publish('greeter', $stored->version);
        $path = $this->tempPath();

        $this->artisan('flow:export', ['name' => 'greeter', '--file' => $path])->assertExitCode(0);

        $this->artisan('flow:import', ['file' => $path, '--name' => 'greeter-copy'])
            ->expectsOutputToContain('Imported flow definition [greeter-copy] version [1] (draft).')
            ->assertExitCode(0);

        $imported = $repository->find('greeter-copy', 1);
        $this->assertSame($published->checksum, $imported->checksum);
        $this->assertSame(StoredDefinition::STATUS_DRAFT, $imported->status);
    }

    public function test_import_command_publishes_immediately_with_the_publish_option(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $repository->publish('greeter', $stored->version);
        $path = $this->tempPath();

        $this->artisan('flow:export', ['name' => 'greeter', '--file' => $path])->assertExitCode(0);

        $this->artisan('flow:import', ['file' => $path, '--name' => 'greeter-copy', '--publish' => true])
            ->expectsOutputToContain('Imported flow definition [greeter-copy] version [1] (published).')
            ->assertExitCode(0);

        $imported = $repository->find('greeter-copy', 1);
        $this->assertSame(StoredDefinition::STATUS_PUBLISHED, $imported->status);
    }

    public function test_import_command_falls_back_to_a_metadata_name_key(): void
    {
        $this->migrateFlowTables();
        $graphWithName = new GraphDefinition(
            [new GraphNode('start', 'test.greet', ['name' => 'Ada'])],
            [],
            ['name' => 'meta-named-copy'],
        );
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $graphWithName);
        $repository->publish('greeter', $stored->version);
        $path = $this->tempPath();

        $this->artisan('flow:export', ['name' => 'greeter', '--file' => $path])->assertExitCode(0);

        $this->artisan('flow:import', ['file' => $path])
            ->expectsOutputToContain('Imported flow definition [meta-named-copy] version [1] (draft).')
            ->assertExitCode(0);
    }

    public function test_import_command_fails_when_the_name_cannot_be_resolved(): void
    {
        $this->migrateFlowTables();
        $repository = $this->repository();
        $stored = $repository->createDraft('greeter', $this->graph());
        $repository->publish('greeter', $stored->version);
        $path = $this->tempPath();

        $this->artisan('flow:export', ['name' => 'greeter', '--file' => $path])->assertExitCode(0);

        $this->artisan('flow:import', ['file' => $path])
            ->expectsOutputToContain('flow:import requires --name or a "metadata.name"')
            ->assertExitCode(1);
    }

    public function test_import_command_reports_violations_and_exits_non_zero_for_an_invalid_graph(): void
    {
        $this->migrateFlowTables();
        $path = $this->tempPath();
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'kind' => 'laravel-flow',
            'metadata' => [],
            'nodes' => [['id' => 'start', 'type' => 'nonexistent.node.type', 'config' => [], 'position' => null]],
            'connections' => [],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('flow:import', ['file' => $path, '--name' => 'broken'])
            ->expectsOutputToContain('Unknown node type [nonexistent.node.type]')
            ->assertExitCode(1);

        $this->assertNull($this->repository()->latest('broken'));
    }

    public function test_import_command_fails_when_the_file_does_not_exist(): void
    {
        $this->migrateFlowTables();

        $this->artisan('flow:import', ['file' => sys_get_temp_dir().'/does-not-exist-flow.json', '--name' => 'x'])
            ->expectsOutputToContain('could not be read')
            ->assertExitCode(1);
    }

    public function test_import_command_rejects_an_unknown_format_option(): void
    {
        $this->migrateFlowTables();
        $path = $this->tempPath();
        file_put_contents($path, '{}');

        $this->artisan('flow:import', ['file' => $path, '--name' => 'x', '--format' => 'bogus'])
            ->expectsOutputToContain('Unsupported --format [bogus]')
            ->assertExitCode(1);
    }
}
