<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

final class CancelRunTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_cancel_aborts_a_paused_run_and_terminates_its_active_nodes(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.cancel.paused')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $paused = $engine->execute('flow.cancel.paused', []);
        $this->assertSame(FlowRun::STATUS_PAUSED, $paused->status);

        $cancelled = $engine->cancel($paused->id);

        // The returned run and the persisted row are both `aborted`.
        $this->assertSame(FlowRun::STATUS_ABORTED, $cancelled->status);
        $this->assertSame('aborted', FlowRunRecord::query()->findOrFail($paused->id)->status);

        $nodes = FlowRunNodeRecord::query()
            ->where('run_id', $paused->id)
            ->pluck('status', 'node_id');

        // The already-completed step is untouched; the paused approval node
        // (running/paused → failed) is terminated.
        $this->assertSame('succeeded', $nodes['create']);
        $this->assertSame('failed', $nodes['manager']);
    }

    public function test_cancel_is_idempotent_for_an_already_terminal_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.cancel.completed')
            ->step('create', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.cancel.completed', []);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);

        // Cancelling a terminal run returns its CURRENT state, unchanged.
        $cancelled = $engine->cancel($run->id);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $cancelled->status);
        $this->assertSame('succeeded', FlowRunRecord::query()->findOrFail($run->id)->status);
    }

    public function test_cancel_throws_for_an_unknown_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->expectException(FlowExecutionException::class);
        $engine->cancel('does-not-exist');
    }

    public function test_cancel_requires_persistence_enabled(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $this->app->forgetInstance(FlowEngine::class);
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);
        $engine->cancel('any-run');
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
