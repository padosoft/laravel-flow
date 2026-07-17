<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

/**
 * Covers the programmatic {@see FlowEngine::replay()} @api seam's legacy path
 * and its error contract. The pinned-graph replay path shares its logic with
 * the `flow:replay` command's pinned path (exercised by
 * {@see ReplayFlowRunCommandTest}).
 */
final class ReplayRunTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_replay_reexecutes_a_terminal_run_as_a_new_linked_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.replay.ok')
            ->step('s', AlwaysSucceedsHandler::class)
            ->register();

        $original = $engine->execute('flow.replay.ok', ['k' => 'v']);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $original->status);

        $replayed = $engine->replay($original->id);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $replayed->status);
        $this->assertNotSame($original->id, $replayed->id);
        // The new run is linked back to the source run.
        $this->assertSame($original->id, $replayed->replayedFromRunId);
    }

    public function test_replay_throws_for_a_non_terminal_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.replay.paused')
            ->step('s', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $paused = $engine->execute('flow.replay.paused', []);
        $this->assertSame(FlowRun::STATUS_PAUSED, $paused->status);

        $this->expectException(FlowExecutionException::class);
        $engine->replay($paused->id);
    }

    public function test_replay_throws_for_an_unknown_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->expectException(FlowExecutionException::class);
        $engine->replay('does-not-exist');
    }

    public function test_replay_requires_persistence_enabled(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $this->app->forgetInstance(FlowEngine::class);
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);
        $engine->replay('any-run');
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }
}
