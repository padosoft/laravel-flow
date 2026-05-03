<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

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

final class FlowDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
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

        $run = (new RunFlowJob('flow.job.handle', dispatchId: 'dispatch-1'))->handle($engine, $this->app->make('cache'));

        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }

    public function test_run_flow_job_does_not_execute_when_dispatch_lock_is_held(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);
        $engine->define('flow.job.locked')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $job = new RunFlowJob('flow.job.locked', dispatchId: 'locked-dispatch');
        $lock = Cache::store()->getStore()->lock($job->lockKey(), 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(FlowExecutionException::class);
            $this->expectExceptionMessage('already being processed');

            $job->handle($engine, $this->app->make('cache'));
        } finally {
            $lock->release();
        }

        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }
}
