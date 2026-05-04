<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
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
                && $job->lockStore === 'array'
                && $job->lockSeconds === 3600
                && $job->lockRetrySeconds === 30
                && $job->tries() === null
                && $job->backoff() === null
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
        $this->app['config']->set('laravel-flow.queue.lock_retry_seconds', 15);
        $this->app['config']->set('laravel-flow.queue.tries', '1');
        $this->app['config']->set('laravel-flow.queue.backoff_seconds', '5,30,120');
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.configured')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch(
            'flow.dispatch.configured',
            ['tenant' => 'acme'],
            FlowExecutionOptions::make(idempotencyKey: 'idem-configured'),
        );

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->lockStore === 'file'
                && $job->lockSeconds === 120
                && $job->lockRetrySeconds === 15
                && $job->tries === 1
                && $job->tries() === 1
                && $job->backoff() === [5, 30, 120]
                && $job->options?->idempotencyKey === 'idem-configured'
                && $job->input === ['tenant' => 'acme'],
        );
    }

    public function test_dispatch_sanitizes_configured_queue_retry_policy(): void
    {
        Bus::fake();
        $this->app['config']->set('laravel-flow.queue.tries', '0.5');
        $this->app['config']->set('laravel-flow.queue.backoff_seconds', [-1, 0, 10, 'invalid', '1.5', '1e3']);
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.retry-sanitized')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch(
            'flow.dispatch.retry-sanitized',
            [],
            FlowExecutionOptions::make(idempotencyKey: 'idem-sanitized'),
        );

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->tries === null
                && $job->tries() === null
                && $job->backoff() === [0, 10],
        );
    }

    public function test_dispatch_allows_retry_policy_on_sync_queue_driver_without_persisted_idempotency(): void
    {
        Bus::fake();
        $this->app['config']->set('queue.default', 'inline');
        $this->app['config']->set('queue.connections.inline.driver', 'sync');
        $this->app['config']->set('laravel-flow.queue.tries', 2);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.retry-sync')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch('flow.dispatch.retry-sync', []);

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->tries === 2
                && $job->tries() === 2,
        );
    }

    public function test_dispatch_preserves_zero_tries_as_laravel_unlimited_retries(): void
    {
        Bus::fake();
        $this->app['config']->set('laravel-flow.queue.tries', 0);
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.retry-unlimited')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch(
            'flow.dispatch.retry-unlimited',
            [],
            FlowExecutionOptions::make(idempotencyKey: 'idem-unlimited'),
        );

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->tries === 0
                && $job->tries() === 0,
        );
    }

    public function test_dispatch_accepts_scalar_queue_backoff_policy(): void
    {
        Bus::fake();
        $this->app['config']->set('laravel-flow.queue.tries', 1);
        $this->app['config']->set('laravel-flow.queue.backoff_seconds', 45);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.retry-scalar')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch('flow.dispatch.retry-scalar', []);

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->tries === 1
                && $job->tries() === 1
                && $job->backoff() === 45,
        );
    }

    public function test_dispatch_rejects_whole_run_retries_without_persisted_idempotency(): void
    {
        Bus::fake();
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database.driver', 'database');
        $this->app['config']->set('laravel-flow.queue.tries', 2);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.retry-unsafe')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('Async queued run retries can re-run the whole flow');

        try {
            $engine->dispatch('flow.dispatch.retry-unsafe', []);
        } finally {
            Bus::assertNotDispatched(RunFlowJob::class);
        }
    }

    public function test_dispatch_rejects_backoff_only_retry_policy_on_async_queue_driver(): void
    {
        Bus::fake();
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database.driver', 'database');
        $this->app['config']->set('laravel-flow.queue.backoff_seconds', 5);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.backoff-unsafe')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('Async queued run retries can re-run the whole flow');

        try {
            $engine->dispatch('flow.dispatch.backoff-unsafe', []);
        } finally {
            Bus::assertNotDispatched(RunFlowJob::class);
        }
    }

    public function test_dispatch_captures_default_cache_store_when_lock_store_is_null(): void
    {
        Bus::fake();
        $this->app['config']->set('cache.default', 'file');
        $this->app['config']->set('laravel-flow.queue.lock_store', null);
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.dispatch.default-lock-store')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dispatch('flow.dispatch.default-lock-store', []);

        Bus::assertDispatched(
            RunFlowJob::class,
            static fn (RunFlowJob $job): bool => $job->lockStore === 'file',
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

        $job = new RunFlowJob('flow.job.locked', dispatchId: 'locked-dispatch', lockStore: 'file', lockSeconds: 60, lockRetrySeconds: 5);
        $job->withFakeQueueInteractions();
        $lock = Cache::store('file')->getStore()->lock($job->lockKey(), 60);
        $this->assertTrue($lock->get());

        try {
            $run = $job->handle($engine, $this->app->make('cache'), $this->app['config']);
        } finally {
            $lock->release();
        }

        $this->assertNull($run);
        $job->assertReleased(5);
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_caps_duplicate_release_delay_to_lock_ttl(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.locked-cap')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $job = new RunFlowJob('flow.job.locked-cap', dispatchId: 'locked-cap-dispatch', lockStore: 'file', lockSeconds: 10, lockRetrySeconds: 60);
        $job->withFakeQueueInteractions();
        $lock = Cache::store('file')->getStore()->lock($job->lockKey(), 10);
        $this->assertTrue($lock->get());

        try {
            $run = $job->handle($engine, $this->app->make('cache'), $this->app['config']);
        } finally {
            $lock->release();
        }

        $this->assertNull($run);
        $job->assertReleased(10);
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

    public function test_run_flow_job_rechecks_completion_marker_after_acquiring_lock(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.completed-race')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $repository = $this->createMock(CacheRepository::class);
        $repository->expects($this->once())->method('getStore')->willReturn(Cache::store('file')->getStore());
        $repository->expects($this->exactly(2))->method('get')->willReturnOnConsecutiveCalls(null, true);
        $repository->expects($this->never())->method('put');

        $cache = $this->createMock(CacheFactory::class);
        $cache->expects($this->once())->method('store')->willReturn($repository);

        $run = (new RunFlowJob('flow.job.completed-race', dispatchId: 'completed-race-dispatch', lockStore: 'file'))
            ->handle($engine, $cache, $this->app['config']);

        $this->assertNull($run);
        $lock = Cache::store('file')->getStore()->lock('laravel-flow:run:completed-race-dispatch', 60);
        $this->assertTrue($lock->get());
        $lock->release();
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_surfaces_completion_marker_write_failures(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.marker-failure')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $repository = $this->createMock(CacheRepository::class);
        $repository->expects($this->once())->method('getStore')->willReturn(Cache::store('file')->getStore());
        $repository->expects($this->exactly(2))->method('get')->willReturn(null);
        $repository->expects($this->once())->method('put')->willReturn(false);

        $cache = $this->createMock(CacheFactory::class);
        $cache->expects($this->once())->method('store')->willReturn($repository);

        $job = new RunFlowJob('flow.job.marker-failure', dispatchId: 'marker-failure-dispatch', lockStore: 'file');

        try {
            $job->handle($engine, $cache, $this->app['config']);
            $this->fail('Expected completion marker write failure to surface.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('completion marker', $e->getMessage());
        }

        $lock = Cache::store('file')->getStore()->lock($job->lockKey(), 60);
        $this->assertFalse($lock->get());
        $lock->forceRelease();
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_retains_lock_when_completion_marker_write_throws(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.marker-exception')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $repository = $this->createMock(CacheRepository::class);
        $repository->expects($this->once())->method('getStore')->willReturn(Cache::store('file')->getStore());
        $repository->expects($this->exactly(2))->method('get')->willReturn(null);
        $repository->expects($this->once())->method('put')->willThrowException(new RuntimeException('cache unavailable'));

        $cache = $this->createMock(CacheFactory::class);
        $cache->expects($this->once())->method('store')->willReturn($repository);

        $job = new RunFlowJob('flow.job.marker-exception', dispatchId: 'marker-exception-dispatch', lockStore: 'file');

        try {
            $job->handle($engine, $cache, $this->app['config']);
            $this->fail('Expected completion marker write exception to surface.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('completion marker', $e->getMessage());
            $this->assertSame('cache unavailable', $e->getPrevious()?->getMessage());
        }

        $lock = Cache::store('file')->getStore()->lock($job->lockKey(), 60);
        $this->assertFalse($lock->get());
        $lock->forceRelease();
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_deserializes_legacy_payload_without_lock_metadata(): void
    {
        $class = RunFlowJob::class;
        $name = 'flow.legacy';
        $payload = sprintf(
            'O:%d:"%s":3:{s:4:"name";s:%d:"%s";s:5:"input";a:0:{}s:7:"options";N;}',
            strlen($class),
            $class,
            strlen($name),
            $name,
        );

        $job = unserialize($payload, ['allowed_classes' => [RunFlowJob::class, FlowExecutionOptions::class]]);

        $this->assertInstanceOf(RunFlowJob::class, $job);
        $this->assertSame('flow.legacy', $job->name);
        $this->assertSame([], $job->input);
        $this->assertNull($job->options);
        $this->assertNull($job->dispatchId);
        $this->assertNull($job->lockStore);
        $this->assertSame(3600, $job->lockSeconds);
        $this->assertSame(30, $job->lockRetrySeconds);
        $this->assertNull($job->tries);
        $this->assertNull($job->tries());
        $this->assertNull($job->backoffSeconds);
        $this->assertNull($job->backoff());

        $secondJob = unserialize($payload, ['allowed_classes' => [RunFlowJob::class, FlowExecutionOptions::class]]);

        $this->assertInstanceOf(RunFlowJob::class, $secondJob);
        $this->assertNotSame($job->lockKey(), $secondJob->lockKey());
        $this->assertStringStartsWith('laravel-flow:run:legacy-', $job->lockKey());
    }

    public function test_run_flow_job_exposes_laravel_retry_policy(): void
    {
        $job = new RunFlowJob(
            'flow.job.retry-policy',
            dispatchId: 'retry-policy-dispatch',
            tries: 3,
            backoffSeconds: 45,
        );

        $this->assertSame(3, $job->tries);
        $this->assertSame(3, $job->tries());
        $this->assertSame(45, $job->backoff());
    }

    public function test_run_flow_job_ignores_negative_scalar_backoff_policy(): void
    {
        $job = new RunFlowJob(
            'flow.job.retry-policy-negative',
            dispatchId: 'retry-policy-negative-dispatch',
            backoffSeconds: -1,
        );

        $this->assertNull($job->backoffSeconds);
        $this->assertNull($job->backoff());
    }

    public function test_run_flow_job_fails_queue_job_when_completion_marker_write_fails(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.marker-failure-queued')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $repository = $this->createMock(CacheRepository::class);
        $repository->expects($this->once())->method('getStore')->willReturn(Cache::store('file')->getStore());
        $repository->expects($this->exactly(2))->method('get')->willReturn(null);
        $repository->expects($this->once())->method('put')->willReturn(false);

        $cache = $this->createMock(CacheFactory::class);
        $cache->expects($this->once())->method('store')->willReturn($repository);

        $job = new RunFlowJob('flow.job.marker-failure-queued', dispatchId: 'marker-failure-queued-dispatch', lockStore: 'file');
        $job->withFakeQueueInteractions();

        try {
            $job->handle($engine, $cache, $this->app['config']);
            $this->fail('Expected completion marker write failure to surface.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('completion marker', $e->getMessage());
        }

        $job->assertFailedWith(RuntimeException::class);
        $lock = Cache::store('file')->getStore()->lock($job->lockKey(), 60);
        $this->assertFalse($lock->get());
        $lock->forceRelease();
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
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

    public function test_run_flow_job_rejects_array_lock_store_for_actual_non_sync_job_connection(): void
    {
        $this->app['config']->set('queue.default', 'inline');
        $this->app['config']->set('queue.connections.inline.driver', 'sync');
        $this->app['config']->set('queue.connections.worker.driver', 'database');

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.array-lock-worker')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $job = new RunFlowJob('flow.job.array-lock-worker', dispatchId: 'array-lock-worker-dispatch', lockStore: 'array');
        $job->withFakeQueueInteractions();
        $property = new \ReflectionProperty($job->job, 'connectionName');
        $property->setValue($job->job, 'worker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('array store is process-local');

        $job->handle($engine, $this->app->make('cache'), $this->app['config']);
    }

    public function test_run_flow_job_allows_process_local_array_lock_store_with_sync_queue_driver(): void
    {
        $this->app['config']->set('queue.default', 'inline');
        $this->app['config']->set('queue.connections.inline.driver', 'sync');

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
