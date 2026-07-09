<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Persistence\EloquentDefinitionRepository;
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

    /**
     * Copilot verdict finding #1 (B-PR7 local review): createDraftIfChanged()
     * skips (returns null) inside a locked comparison, but the fallback
     * latest() call is a second, UNLOCKED query — if a concurrent writer
     * drafts a new version for the same name in that window, latest() can
     * return a row that does not match the graph just registered. Real
     * concurrency cannot be interleaved on the shared-connection sqlite
     * test database (same limitation documented in
     * DefinitionRepositoryTest for the lock itself), so this test
     * simulates the window directly: a fake repository returns null from
     * createDraftIfChanged() (dedupe skip) but a latest() row whose
     * checksum does NOT match the graph being registered. The run must
     * stay unpinned rather than being pinned to that mismatched version.
     */
    public function test_a_latest_version_that_does_not_match_the_registered_graph_leaves_the_run_unpinned(): void
    {
        $this->migrateFlowTables();

        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.definitions.persist_registered', true);
        $this->app->forgetInstance(FlowEngine::class);

        $this->app->bind(DefinitionRepository::class, function ($app) {
            return new class($app->make(EloquentDefinitionRepository::class)) implements DefinitionRepository
            {
                public function __construct(private readonly EloquentDefinitionRepository $inner) {}

                public function createDraftIfChanged(string $name, GraphDefinition $graph): ?StoredDefinition
                {
                    return null;
                }

                public function latest(string $name, ?string $status = null): ?StoredDefinition
                {
                    return new StoredDefinition(
                        id: 999,
                        name: $name,
                        version: 7,
                        status: StoredDefinition::STATUS_DRAFT,
                        graph: [],
                        checksum: 'mismatched-checksum-from-a-concurrent-writer',
                        signature: null,
                        publishedAt: null,
                    );
                }

                public function createDraft(string $name, GraphDefinition $graph): StoredDefinition
                {
                    return $this->inner->createDraft($name, $graph);
                }

                public function find(string $name, int $version): StoredDefinition
                {
                    return $this->inner->find($name, $version);
                }

                public function publish(string $name, int $version): StoredDefinition
                {
                    return $this->inner->publish($name, $version);
                }

                public function archive(string $name, int $version): StoredDefinition
                {
                    return $this->inner->archive($name, $version);
                }

                public function versions(string $name): array
                {
                    return $this->inner->versions($name);
                }
            };
        });

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.pinned-race')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.pinned-race', []);

        $record = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $record);
        $this->assertNull($record->definition_version);
        $this->assertNull($record->definition_checksum);
    }

    /**
     * Round-2 local Copilot review of the fix above: only SKIPPING the pin
     * write on a lost race leaves a STALE pin from an earlier registration
     * of the same name in place (e.g. Octane workers, host apps that
     * re-register on every boot). The fix added an explicit unset(); this
     * test proves it — unlike the test above (first-ever registration,
     * where "never wrote" and "explicitly unset" are indistinguishable),
     * this one succeeds a FIRST registration (populating a real pin), then
     * loses the race on a SECOND registration of the same name, and
     * asserts the run created after that second, lost-race registration is
     * unpinned rather than reusing the first registration's pin.
     */
    public function test_losing_the_race_on_a_repeated_registration_clears_the_prior_pin_instead_of_reusing_it(): void
    {
        $this->migrateFlowTables();

        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.definitions.persist_registered', true);
        $this->app->forgetInstance(FlowEngine::class);

        $this->app->singleton(DefinitionRepository::class, function ($app) {
            return new class($app->make(EloquentDefinitionRepository::class)) implements DefinitionRepository
            {
                private int $calls = 0;

                public function __construct(private readonly EloquentDefinitionRepository $inner) {}

                public function createDraftIfChanged(string $name, GraphDefinition $graph): ?StoredDefinition
                {
                    $this->calls++;

                    // First registration: real dedupe-miss, real draft created.
                    if ($this->calls === 1) {
                        return $this->inner->createDraftIfChanged($name, $graph);
                    }

                    // Second registration: simulate a lost race (dedupe-skip
                    // whose unlocked latest() fallback won't match below).
                    return null;
                }

                public function latest(string $name, ?string $status = null): ?StoredDefinition
                {
                    if ($this->calls <= 1) {
                        return $this->inner->latest($name, $status);
                    }

                    return new StoredDefinition(
                        id: 999,
                        name: $name,
                        version: 7,
                        status: StoredDefinition::STATUS_DRAFT,
                        graph: [],
                        checksum: 'mismatched-checksum-from-a-concurrent-writer',
                        signature: null,
                        publishedAt: null,
                    );
                }

                public function createDraft(string $name, GraphDefinition $graph): StoredDefinition
                {
                    return $this->inner->createDraft($name, $graph);
                }

                public function find(string $name, int $version): StoredDefinition
                {
                    return $this->inner->find($name, $version);
                }

                public function publish(string $name, int $version): StoredDefinition
                {
                    return $this->inner->publish($name, $version);
                }

                public function archive(string $name, int $version): StoredDefinition
                {
                    return $this->inner->archive($name, $version);
                }

                public function versions(string $name): array
                {
                    return $this->inner->versions($name);
                }
            };
        });

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.pinned-stale-race')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        // Second registration of the SAME name loses the simulated race.
        $engine->define('flow.pinned-stale-race')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.pinned-stale-race', []);

        $record = FlowRunRecord::query()->find($run->id);
        $this->assertInstanceOf(FlowRunRecord::class, $record);
        $this->assertNull($record->definition_version);
        $this->assertNull($record->definition_checksum);
    }

    /**
     * Copilot review (Macro B PR #54): persistRegisteredDefinitionIfEnabled()
     * only wrapped QueryException, but createDraftIfChanged()/checksum()
     * can also throw JsonException (e.g. invalid UTF-8 in a step handler
     * name flowing into the compiled graph's node config) — that would
     * otherwise leak a low-level JsonException out of registerDefinition()
     * instead of the package-level exception this feature otherwise
     * reports persistence errors through.
     */
    public function test_registering_a_definition_with_an_unencodable_graph_throws_a_clean_exception(): void
    {
        $this->migrateFlowTables();
        $engine = $this->enginePersistingRunsAndDefinitions();

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('flow.pinned-unencodable');

        $engine->define('flow.pinned-unencodable')
            ->step('one', "AlwaysSucceedsHandler\xB1\x31")
            ->register();
    }

    /**
     * Copilot review (Macro B PR #54): the original fix left
     * toGraphDefinition() OUTSIDE the try/catch, so a definition compiling
     * to a structurally invalid graph (e.g. zero steps — unreachable via
     * the builder's own guard, but reachable via a FlowDefinition built
     * directly) would still leak InvalidGraphException instead of the
     * package-level exception this feature reports other failures through.
     */
    public function test_registering_a_structurally_invalid_definition_throws_a_clean_exception(): void
    {
        $this->migrateFlowTables();
        $engine = $this->enginePersistingRunsAndDefinitions();

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('flow.pinned-invalid-graph');

        $engine->registerDefinition(new FlowDefinition('flow.pinned-invalid-graph', [], []));
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
