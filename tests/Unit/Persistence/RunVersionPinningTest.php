<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;

/**
 * Task 11 (B-PR7): a run created from a `persist_registered` definition
 * is pinned to the matched/produced `flow_definitions` version and its
 * content checksum; runs for definitions registered without the flag
 * stay unpinned (both columns null).
 */
final class RunVersionPinningTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_run_created_from_a_persisted_registered_flow_carries_version_and_checksum(): void
    {
        $this->migrateFlowTables();
        $engine = $this->enginePersistingRunsAndDefinitions();

        $engine->define('flow.pinned')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $definition = $engine->definition('flow.pinned');
        $expectedChecksum = (new GraphSerializer)->checksum($definition->toGraphDefinition());

        $run = $engine->execute('flow.pinned', []);

        $record = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $record);
        $this->assertSame(1, $record->definition_version);
        $this->assertSame($expectedChecksum, $record->definition_checksum);
    }

    public function test_unpinned_legacy_run_has_null_version_and_checksum(): void
    {
        $this->migrateFlowTables();
        $engine = $this->enginePersistingRunsOnly();

        $engine->define('flow.unpinned')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.unpinned', []);

        $record = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $record);
        $this->assertNull($record->definition_version);
        $this->assertNull($record->definition_checksum);
    }

    public function test_reregistering_an_identical_definition_pins_the_matched_existing_version(): void
    {
        $this->migrateFlowTables();
        $engine = $this->enginePersistingRunsAndDefinitions();

        $engine->define('flow.pinned-matched')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        // Re-registering the SAME definition must not create a second
        // draft version (see RegisterPersistsDraftTest); the run pin
        // should still resolve to the matched version 1, not go unpinned.
        $engine->define('flow.pinned-matched')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.pinned-matched', []);

        $record = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $record);
        $this->assertSame(1, $record->definition_version);
        $this->assertNotNull($record->definition_checksum);
    }

    public function test_reregistering_a_changed_definition_pins_runs_to_the_new_version(): void
    {
        $this->migrateFlowTables();
        $engine = $this->enginePersistingRunsAndDefinitions();

        $engine->define('flow.pinned-changed')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $firstRun = $engine->execute('flow.pinned-changed', []);
        $firstRecord = FlowRunRecord::query()->find($firstRun->id);
        $this->assertInstanceOf(FlowRunRecord::class, $firstRecord);
        $this->assertSame(1, $firstRecord->definition_version);

        $engine->define('flow.pinned-changed')
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', SecondHandler::class)
            ->register();

        $secondRun = $engine->execute('flow.pinned-changed', []);
        $secondRecord = FlowRunRecord::query()->find($secondRun->id);
        $this->assertInstanceOf(FlowRunRecord::class, $secondRecord);
        $this->assertSame(2, $secondRecord->definition_version);
        $this->assertNotSame($firstRecord->definition_checksum, $secondRecord->definition_checksum);
    }

    private function enginePersistingRunsAndDefinitions(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.definitions.persist_registered', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }

    private function enginePersistingRunsOnly(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }
}
