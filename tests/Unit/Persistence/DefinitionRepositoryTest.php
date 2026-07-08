<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;

final class DefinitionRepositoryTest extends PersistenceTestCase
{
    private function repository(): DefinitionRepository
    {
        return $this->app->make(DefinitionRepository::class);
    }

    private function graph(string $nodeId = 'start'): GraphDefinition
    {
        return new GraphDefinition([new GraphNode($nodeId, 'flow.start')], []);
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
}
