<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Jobs\RunFlowJob;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use RuntimeException;

final class FlowDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
        Cache::store('file')->flush();
        Cache::store('array')->flush();
    }

    public function test_dispatch_queues_a_run_flow_job_after_validation(): void
    {
        Bus::fake();

        Flow::define('flow.dispatch')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        Flow::dispatch(
            'flow.dispatch',
            ['tenant' => 'acme'],
            FlowExecutionOptions::make(correlationId: 'corr-1', idempotencyKey: 'idem-1'),
        );

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->name === 'flow.dispatch'
                && $job instanceof ShouldQueueAfterCommit
                && $job->dispatchId !== null
                && $job->lockSeconds === 3600
                && str_starts_with($job->lockKey(), 'laravel-flow:run:')
                && $job->input === ['tenant' => 'acme']
                && $job->options?->correlationId === 'corr-1'
                && $job->options?->idempotencyKey === 'idem-1',
        );
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }

    public function test_dispatch_uses_configured_queue_lock_settings(): void
    {
        Bus::fake();
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app['config']->set('laravel-flow.queue.lock_seconds', 120);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.configured')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch('flow.dispatch.configured', ['tenant' => 'acme']);

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->lockStore === 'file'
                && $job->lockSeconds === 120
                && $job->input === ['tenant' => 'acme'],
        );
    }

    public function test_dispatch_does_not_queue_when_input_validation_fails(): void
    {
        Bus::fake();

        Flow::define('flow.dispatch.invalid')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        try {
            Flow::dispatch('flow.dispatch.invalid', []);
            $this->fail('Expected dispatch input validation to fail.');
        } catch (FlowInputException $e) {
            $this->assertStringContainsString('tenant', $e->getMessage());
        }

        Bus::assertNotDispatched(RunFlowJob::class);
    }

    public function test_run_flow_job_executes_the_registered_flow_in_the_worker(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.handle')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $job = new RunFlowJob('flow.job.handle', dispatchId: 'dispatch-1', lockStore: 'file');

        $run = $job->handle($engine, $this->app->make('cache'), $this->app['config']);

        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertTrue(Cache::store('file')->get($job->completionKey()));
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_releases_duplicate_delivery_when_dispatch_lock_is_held(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.locked')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $job = new RunFlowJob('flow.job.locked', dispatchId: 'locked-dispatch', lockStore: 'file', lockSeconds: 60);
        $job->withFakeQueueInteractions();
        $lock = Cache::store('file')->getStore()->lock($job->lockKey(), 60);
        $this->assertTrue($lock->get());

        try {
            $run = $job->handle($engine, $this->app->make('cache'), $this->app['config']);
        } finally {
            $lock->release();
        }

        $this->assertNull($run);
        $job->assertReleased(60);
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_acknowledges_completed_duplicate_delivery(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.completed-duplicate')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $job = new RunFlowJob('flow.job.completed-duplicate', dispatchId: 'completed-dispatch', lockStore: 'file');
        $job->withFakeQueueInteractions();
        Cache::store('file')->put($job->completionKey(), true, 60);

        $run = $job->handle($engine, $this->app->make('cache'), $this->app['config']);

        $this->assertNull($run);
        $job->assertNotReleased();
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_rejects_process_local_array_lock_store(): void
    {
        $this->app['config']->set('queue.default', 'database');

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.array-lock')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('array store is process-local');

        (new RunFlowJob('flow.job.array-lock', dispatchId: 'array-lock-dispatch', lockStore: 'array'))
            ->handle($engine, $this->app->make('cache'), $this->app['config']);
    }

    public function test_run_flow_job_allows_process_local_array_lock_store_with_sync_queue_driver(): void
    {
        $this->app['config']->set('queue.default', 'sync');

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.array-lock-sync')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = (new RunFlowJob('flow.job.array-lock-sync', dispatchId: 'array-lock-sync-dispatch', lockStore: 'array'))
            ->handle($engine, $this->app->make('cache'), $this->app['config']);

        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }
}
