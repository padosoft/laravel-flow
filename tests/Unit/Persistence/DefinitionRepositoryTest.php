<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Models\FlowDefinitionRecord;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;

final class DefinitionRepositoryTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // publish() now validates the stored graph against the node
        // catalog (see EloquentDefinitionRepository::publish()), so every
        // graph built by this suite must reference a type the registry
        // actually knows about.
        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    private function repository(): DefinitionRepository
    {
        return $this->app->make(DefinitionRepository::class);
    }

    private function graph(string $nodeId = 'start'): GraphDefinition
    {
        return new GraphDefinition([new GraphNode($nodeId, 'test.greet', ['name' => 'Ada'])], []);
    }

    private function invalidGraph(string $nodeId = 'start'): GraphDefinition
    {
        // Structurally sound (single node, no wires) but semantically
        // invalid: no such node type is registered, so GraphValidator
        // rejects it. createDraft() intentionally allows this — drafts may
        // be semantically incomplete work-in-progress; only publish()
        // enforces semantic validity.
        return new GraphDefinition([new GraphNode($nodeId, 'nonexistent.node.type')], []);
    }

    public function test_create_draft_auto_increments_version_per_name(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();

        $first = $repository->createDraft('onboarding', $this->graph());
        $second = $repository->createDraft('onboarding', $this->graph('second'));
        $otherName = $repository->createDraft('other-flow', $this->graph());

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(1, $otherName->version);
        $this->assertSame(StoredDefinition::STATUS_DRAFT, $first->status);
        $this->assertSame(StoredDefinition::STATUS_DRAFT, $second->status);
    }

    public function test_find_returns_stored_definition_and_round_trips_graph(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $graph = $this->graph();
        $created = $repository->createDraft('onboarding', $graph);

        $found = $repository->find('onboarding', $created->version);

        $this->assertSame($created->id, $found->id);
        $this->assertSame((new GraphSerializer)->checksum($graph), $found->checksum);

        $rehydrated = (new GraphSerializer)->fromArray($found->graph);
        $this->assertSame(['start'], $rehydrated->nodeIds());
    }

    public function test_find_unknown_definition_throws_not_found(): void
    {
        $this->migrateFlowTables();

        $this->expectException(DefinitionNotFoundException::class);

        $this->repository()->find('missing', 1);
    }

    public function test_latest_returns_null_when_none_and_highest_version_otherwise(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();

        $this->assertNull($repository->latest('onboarding'));

        $repository->createDraft('onboarding', $this->graph());
        $second = $repository->createDraft('onboarding', $this->graph('second'));

        $latest = $repository->latest('onboarding');
        $this->assertNotNull($latest);
        $this->assertSame($second->version, $latest->version);
    }

    public function test_latest_can_be_constrained_to_a_status(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $first = $repository->createDraft('onboarding', $this->graph());
        $repository->createDraft('onboarding', $this->graph('second'));
        $repository->publish('onboarding', $first->version);

        $this->assertNull($repository->latest('onboarding', StoredDefinition::STATUS_ARCHIVED));

        $latestPublished = $repository->latest('onboarding', StoredDefinition::STATUS_PUBLISHED);
        $this->assertNotNull($latestPublished);
        $this->assertSame($first->version, $latestPublished->version);
    }

    public function test_versions_returns_all_versions_ascending(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $repository->createDraft('onboarding', $this->graph());
        $repository->createDraft('onboarding', $this->graph('second'));
        $repository->createDraft('onboarding', $this->graph('third'));

        $versions = $repository->versions('onboarding');

        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], array_map(static fn (StoredDefinition $definition): int => $definition->version, $versions));
    }

    public function test_publish_transitions_draft_to_published_and_archives_previous_published(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $v1 = $repository->createDraft('onboarding', $this->graph());
        $v2 = $repository->createDraft('onboarding', $this->graph('second'));

        $publishedV1 = $repository->publish('onboarding', $v1->version);
        $this->assertSame(StoredDefinition::STATUS_PUBLISHED, $publishedV1->status);
        $this->assertNotNull($publishedV1->publishedAt);

        $publishedV2 = $repository->publish('onboarding', $v2->version);
        $this->assertSame(StoredDefinition::STATUS_PUBLISHED, $publishedV2->status);

        $archivedV1 = $repository->find('onboarding', $v1->version);
        $this->assertSame(StoredDefinition::STATUS_ARCHIVED, $archivedV1->status);
    }

    public function test_publish_on_non_draft_throws_lifecycle_exception(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $definition = $repository->createDraft('onboarding', $this->graph());
        $repository->publish('onboarding', $definition->version);

        $this->expectException(DefinitionLifecycleException::class);

        $repository->publish('onboarding', $definition->version);
    }

    public function test_archive_transitions_draft_or_published_to_archived(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $draft = $repository->createDraft('onboarding', $this->graph());

        $archived = $repository->archive('onboarding', $draft->version);

        $this->assertSame(StoredDefinition::STATUS_ARCHIVED, $archived->status);
    }

    public function test_archive_on_archived_throws_lifecycle_exception(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $definition = $repository->createDraft('onboarding', $this->graph());
        $repository->archive('onboarding', $definition->version);

        $this->expectException(DefinitionLifecycleException::class);

        $repository->archive('onboarding', $definition->version);
    }

    public function test_create_draft_allows_a_semantically_invalid_graph(): void
    {
        // Design decision: drafts may be semantically incomplete (Studio
        // saves work-in-progress); only publish() enforces an executable
        // graph. This pins that createDraft() itself never invokes
        // GraphValidator.
        $this->migrateFlowTables();

        $draft = $this->repository()->createDraft('onboarding', $this->invalidGraph());

        $this->assertSame(StoredDefinition::STATUS_DRAFT, $draft->status);
    }

    public function test_publish_rejects_a_semantically_invalid_graph_and_leaves_it_draft(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $draft = $repository->createDraft('onboarding', $this->invalidGraph());

        try {
            $repository->publish('onboarding', $draft->version);
            $this->fail('Expected InvalidGraphException.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('Unknown node type [nonexistent.node.type]', $e->getMessage());
        }

        $stillDraft = $repository->find('onboarding', $draft->version);
        $this->assertSame(StoredDefinition::STATUS_DRAFT, $stillDraft->status);
    }

    public function test_publish_on_a_valid_draft_still_works(): void
    {
        // Regression guard: adding semantic validation to publish() must
        // not break the normal, valid-graph path exercised above by
        // test_publish_transitions_draft_to_published_and_archives_previous_published().
        $this->migrateFlowTables();

        $repository = $this->repository();
        $draft = $repository->createDraft('onboarding', $this->graph());

        $published = $repository->publish('onboarding', $draft->version);

        $this->assertSame(StoredDefinition::STATUS_PUBLISHED, $published->status);
    }

    // Note: the publish() name-group lockForUpdate() fix for the two-
    // concurrent-publishes race (Copilot verdict finding #1) cannot be
    // exercised here. SQLite serializes all writers on the single
    // in-memory test connection regardless of row locks, so no test in
    // this suite can open the race window that the lock protects against
    // on InnoDB. The lock itself, not a test, is the protection; see the
    // docblock on EloquentDefinitionRepository::publish().

    public function test_create_draft_if_changed_creates_once_and_skips_an_identical_second_call(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();
        $graph = $this->graph();

        $created = $repository->createDraftIfChanged('onboarding', $graph);
        $this->assertNotNull($created);
        $this->assertSame(1, $created->version);

        $skipped = $repository->createDraftIfChanged('onboarding', $graph);
        $this->assertNull($skipped);

        $this->assertCount(1, $repository->versions('onboarding'));
    }

    public function test_create_draft_if_changed_creates_a_new_version_when_the_graph_differs(): void
    {
        $this->migrateFlowTables();

        $repository = $this->repository();

        $repository->createDraftIfChanged('onboarding', $this->graph());
        $second = $repository->createDraftIfChanged('onboarding', $this->graph('second'));

        $this->assertNotNull($second);
        $this->assertSame(2, $second->version);
        $this->assertCount(2, $repository->versions('onboarding'));
    }

    // Note: same limitation as the publish() race above — the atomic
    // checksum-compare-then-insert in createDraftIfChanged() (the fix for
    // FlowEngine::persistRegisteredDefinitionIfEnabled() double-drafting
    // an unchanged flow under concurrent registration) needs two
    // interleaved transactions to demonstrate the closed race, which
    // SQLite's whole-connection write serialization cannot open here. The
    // name-group lockForUpdate() acquired before the comparison, not a
    // test, is the protection; see the docblock on
    // EloquentDefinitionRepository::createDraftIfChanged().

    public function test_signing_disabled_by_default_stores_a_null_signature(): void
    {
        $this->migrateFlowTables();

        $created = $this->repository()->createDraft('onboarding', $this->graph());

        $this->assertNull($created->signature);
    }

    public function test_signing_disabled_leaves_a_tampered_graph_column_loadable(): void
    {
        // Design decision: signature verification is skipped entirely while
        // disabled, so tampering that has nothing to do with signing (or a
        // signature left over from before signing was disabled) must not
        // block ordinary reads.
        $this->migrateFlowTables();

        $created = $this->repository()->createDraft('onboarding', $this->graph());
        $this->tamperGraph($created->id, $this->graph('tampered'));

        $found = $this->repository()->find('onboarding', $created->version);

        $this->assertSame(['tampered'], (new GraphSerializer)->fromArray($found->graph)->nodeIds());
    }

    public function test_signing_enabled_persists_and_verifies_the_signature_on_every_read_path(): void
    {
        $this->migrateFlowTables();
        $this->configureSigningSecret('top-secret');

        $repository = $this->repository();
        $created = $repository->createDraft('onboarding', $this->graph());

        $this->assertNotNull($created->signature);

        $found = $repository->find('onboarding', $created->version);
        $this->assertSame($created->signature, $found->signature);

        $latest = $repository->latest('onboarding');
        $this->assertNotNull($latest);
        $this->assertSame($created->signature, $latest->signature);

        $versions = $repository->versions('onboarding');
        $this->assertSame($created->signature, $versions[0]->signature);
    }

    public function test_signing_enabled_rejects_a_tampered_graph_column(): void
    {
        $this->migrateFlowTables();
        $this->configureSigningSecret('top-secret');

        $created = $this->repository()->createDraft('onboarding', $this->graph());
        $this->tamperGraph($created->id, $this->graph('tampered'));

        $this->expectException(DefinitionSignatureException::class);

        $this->repository()->find('onboarding', $created->version);
    }

    public function test_enabling_signing_after_unsigned_rows_exist_fails_to_load_them(): void
    {
        $this->migrateFlowTables();

        $created = $this->repository()->createDraft('onboarding', $this->graph());
        $this->assertNull($created->signature);

        $this->configureSigningSecret('top-secret');

        $this->expectException(DefinitionSignatureException::class);

        $this->repository()->find('onboarding', $created->version);
    }

    public function test_disabling_signing_after_signed_rows_exist_still_loads_them(): void
    {
        // Design decision: disabling signing is tolerant of rows signed
        // while it was enabled — verification is skipped entirely once no
        // secret is configured, so previously signed rows keep loading.
        $this->migrateFlowTables();
        $this->configureSigningSecret('top-secret');

        $created = $this->repository()->createDraft('onboarding', $this->graph());
        $this->assertNotNull($created->signature);

        $this->configureSigningSecret(null);

        $found = $this->repository()->find('onboarding', $created->version);

        $this->assertSame($created->signature, $found->signature);
    }

    public function test_publish_rejects_tampered_draft_when_signing_enabled(): void
    {
        $this->migrateFlowTables();
        $this->configureSigningSecret('top-secret');

        $repository = $this->repository();
        $created = $repository->createDraft('onboarding', $this->graph());
        $this->tamperGraph($created->id, $this->graph('tampered'));

        $this->expectException(DefinitionSignatureException::class);

        $repository->publish('onboarding', $created->version);
    }

    private function configureSigningSecret(?string $secret): void
    {
        $this->app['config']->set('laravel-flow.definitions.signing_secret', $secret);
    }

    private function tamperGraph(int $id, GraphDefinition $replacement): void
    {
        $record = FlowDefinitionRecord::query()->findOrFail($id);
        $record->graph = (new GraphSerializer)->toArray($replacement);
        $record->save();
    }

    public function test_signing_enabled_returns_recomputed_checksum_when_column_is_tampered(): void
    {
        $this->migrateFlowTables();
        $this->configureSigningSecret('top-secret');
        $created = $this->repository()->createDraft('signed.checksum', $this->graph('original'));

        $record = FlowDefinitionRecord::query()->findOrFail($created->id);
        $record->checksum = str_repeat('0', 64);
        $record->save();

        $loaded = $this->repository()->find('signed.checksum', $created->version);

        // graph+signature intact: verification passes and the DTO carries
        // the checksum recomputed from the verified graph, not the column.
        $this->assertSame($created->checksum, $loaded->checksum);
        $this->assertNotSame(str_repeat('0', 64), $loaded->checksum);
    }

    public function test_signing_enabled_wraps_unreadable_graph_in_signature_exception(): void
    {
        $this->migrateFlowTables();
        $this->configureSigningSecret('top-secret');
        $created = $this->repository()->createDraft('signed.broken', $this->graph('original'));

        $record = FlowDefinitionRecord::query()->findOrFail($created->id);
        $record->graph = ['schema_version' => 999, 'kind' => 'nope'];
        $record->save();

        try {
            $this->repository()->find('signed.broken', $created->version);
            $this->fail('Expected DefinitionSignatureException');
        } catch (DefinitionSignatureException $e) {
            $this->assertNotNull($e->getPrevious());
        }
    }
}
