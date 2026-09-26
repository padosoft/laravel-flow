<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\Jobs\ResumeTimerJob;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Executor\TimerResumer;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\TimerNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use RuntimeException;

/**
 * Timed pause (NodeResult::pausedUntil()) through both executors: a synchronous
 * run sleeps inline within a cap; a queued run persists the pause, schedules a
 * delayed job, and the sweeper is the safety net. The test queue driver is
 * `sync`, which cannot delay — exactly the case the sweeper exists for.
 */
final class TimerResumeTest extends PersistenceTestCase
{
    private const NOW = '2026-09-28 10:00:00';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.executor.max_inline_delay_seconds', 5);
        $app['config']->set('laravel-flow.nodes.handlers', [TimerNode::class, QueueProbeNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        Carbon::setTestNow(self::NOW);
        Sleep::fake();
        TimerNode::$executions = 0;
        QueueProbeNode::reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Sleep::fake(false);

        parent::tearDown();
    }

    /**
     * t (timer) -> p (probe)
     */
    private function timerThenProbe(int $seconds): GraphDefinition
    {
        return new GraphDefinition(
            [new GraphNode('t', 'test.timer', ['seconds' => $seconds]), new GraphNode('p', 'test.probe')],
            [new Connection('t', 'out', 'p', 'in')],
        );
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    private function engine(): FlowEngine
    {
        return $this->app->make(FlowEngine::class);
    }

    private function node(string $runId, string $nodeId): object
    {
        return DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', $nodeId)->first();
    }

    public function test_sync_sleeps_inline_within_the_cap_and_succeeds(): void
    {
        $result = $this->runner()->run($this->timerThenProbe(3), []);

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['t']);
        $this->assertSame(['through' => true], $result->nodeOutputs['t']['out']);
        $this->assertSame(1, QueueProbeNode::count('p'));
        Sleep::assertSleptTimes(1);
        // A sync timer never persists a resume time.
        $this->assertNull($this->node($result->runId, 't')->resume_at);
    }

    public function test_sync_fails_actionably_when_the_wait_exceeds_the_cap(): void
    {
        $result = $this->runner()->run($this->timerThenProbe(60), []);

        $this->assertSame(NodeState::Failed, $result->nodeStates['t']);
        $this->assertSame(NodeState::Blocked, $result->nodeStates['p']);
        $this->assertStringContainsString('max_inline_delay_seconds', $result->errors['t']);
        $this->assertStringContainsString('Flow::dispatchGraph', $result->errors['t']);
        Sleep::assertNeverSlept();
        $this->assertSame(0, QueueProbeNode::count('p'));
    }

    public function test_a_time_already_due_completes_without_waiting(): void
    {
        $result = $this->runner()->run($this->timerThenProbe(0), []);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['t']);
        Sleep::assertNeverSlept();
    }

    public function test_a_dry_run_never_waits(): void
    {
        $result = $this->runner()->run($this->timerThenProbe(3600), [], null, true);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['t']);
        Sleep::assertNeverSlept();
        $this->assertSame(0, DB::table('flow_run_nodes')->count());
    }

    public function test_queued_persists_the_pause_then_the_sweeper_resumes_it_once_due(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);

        // Paused, not sleeping: the resume time is persisted and nothing downstream ran.
        $timer = $this->node($runId, 't');
        $this->assertSame('paused', $timer->status);
        $this->assertNotNull($timer->resume_at);
        $this->assertSame('paused', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, QueueProbeNode::count('p'));
        Sleep::assertNeverSlept();

        // Not due yet: the sweeper finds nothing.
        $this->artisan('flow:resume-due-timers')->expectsOutput('0 due timer(s) dispatched.')->assertExitCode(0);
        $this->assertSame('paused', $this->node($runId, 't')->status);

        // Due: it resumes, keeps the stored outputs, and the run finishes.
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61));
        $this->artisan('flow:resume-due-timers')->expectsOutput('1 due timer(s) dispatched.')->assertExitCode(0);

        $timer = $this->node($runId, 't');
        $this->assertSame('succeeded', $timer->status);
        // The due time is kept after the resume (it is how a retry recognises a completed timer).
        $this->assertNotNull($timer->resume_at);
        $this->assertSame(['through' => true], json_decode((string) $timer->outputs, true)['out']);
        $this->assertSame(1, QueueProbeNode::count('p'));
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
        // The handler ran once; resuming never re-executes it.
        $this->assertSame(1, TimerNode::$executions);
    }

    public function test_resuming_twice_never_re_runs_anything(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61));

        $resumer = $this->app->make(TimerResumer::class);

        $this->assertTrue($resumer->resume($runId, 't')->wasResumed());
        $this->assertSame(1, QueueProbeNode::count('p'));
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));

        // A duplicate re-enters the (idempotent) coordinator: it claims nothing,
        // so neither the handler nor the downstream node runs again.
        $resumer->resume($runId, 't');
        $this->assertSame(1, TimerNode::$executions);
        $this->assertSame(1, QueueProbeNode::count('p'));
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
    }

    public function test_a_failed_coordinator_enqueue_after_the_flip_is_recovered_by_a_retry(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61));

        // A queue that is down exactly once, after the timer flip has committed.
        $bus = new class($this->app) extends Dispatcher
        {
            public int $failures = 1;

            public function dispatch($command)
            {
                if ($command instanceof CoordinatorJob && $this->failures-- > 0) {
                    throw new RuntimeException('queue backend unavailable');
                }

                return parent::dispatch($command);
            }
        };
        $resumer = new TimerResumer(
            $this->app->make(ConnectionResolverInterface::class),
            $this->app->make(FlowStore::class),
            static fn (): \DateTimeImmutable => Carbon::now()->toDateTimeImmutable(),
            $bus,
        );

        try {
            $resumer->resume($runId, 't');
            $this->fail('the enqueue failure must surface so the job is retried');
        } catch (RuntimeException) {
            // expected
        }

        // The flip committed but nothing advanced the run: without a retry path it is stuck forever.
        $this->assertSame('succeeded', $this->node($runId, 't')->status);
        $this->assertSame(0, QueueProbeNode::count('p'));
        $this->assertSame('running', DB::table('flow_runs')->where('id', $runId)->value('status'));

        // The retry finds a completed timer and re-drives the coordinator.
        $this->assertTrue($resumer->resume($runId, 't')->wasResumed());
        $this->assertSame(1, QueueProbeNode::count('p'));
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(1, TimerNode::$executions);
    }

    public function test_a_timer_that_is_not_due_reports_when_it_is(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);

        $outcome = $this->app->make(TimerResumer::class)->resume($runId, 't');

        $this->assertTrue($outcome->isNotDue());
        $this->assertSame(Carbon::parse(self::NOW)->addSeconds(60)->getTimestamp(), $outcome->dueAt?->getTimestamp());
        $this->assertSame('paused', $this->node($runId, 't')->status);
    }

    public function test_the_sweeper_recovers_a_completed_timer_whose_run_stalled(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61));

        // A queue outage right after the flip, with no job retry coming (as in a
        // `--sync` sweep): the timer is completed but nothing advances the run.
        $bus = new class($this->app) extends Dispatcher
        {
            public function dispatch($command)
            {
                if ($command instanceof CoordinatorJob) {
                    throw new RuntimeException('queue backend unavailable');
                }

                return parent::dispatch($command);
            }
        };
        $resumer = new TimerResumer(
            $this->app->make(ConnectionResolverInterface::class),
            $this->app->make(FlowStore::class),
            static fn (): \DateTimeImmutable => Carbon::now()->toDateTimeImmutable(),
            $bus,
        );

        try {
            $resumer->resume($runId, 't');
            $this->fail('the enqueue failure must surface');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('succeeded', $this->node($runId, 't')->status);
        $this->assertSame(0, QueueProbeNode::count('p'));

        // Inside the grace period a healthy run between two passes is left alone.
        $this->artisan('flow:resume-due-timers')->expectsOutput('0 due timer(s) dispatched.')->assertExitCode(0);
        $this->assertSame(0, QueueProbeNode::count('p'));

        // Past it, the ordinary sweep re-drives the stalled run to completion.
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61 + 61));
        $this->artisan('flow:resume-due-timers')->expectsOutput('1 due timer(s) dispatched.')->assertExitCode(0);

        $this->assertSame(1, QueueProbeNode::count('p'));
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(1, TimerNode::$executions);

        // A finished run is never picked again.
        $this->artisan('flow:resume-due-timers')->expectsOutput('0 due timer(s) dispatched.')->assertExitCode(0);
    }

    public function test_a_leaf_timer_whose_run_only_needs_finalizing_is_recovered_too(): void
    {
        // The timer is the graph's ONLY node: after the flip there is nothing
        // pending, the run is merely waiting for the coordinator to finalize it.
        $graph = new GraphDefinition([new GraphNode('t', 'test.timer', ['seconds' => 60])], []);
        $runId = $this->engine()->dispatchGraph($graph, []);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61));

        $bus = new class($this->app) extends Dispatcher
        {
            public function dispatch($command)
            {
                if ($command instanceof CoordinatorJob) {
                    throw new RuntimeException('queue backend unavailable');
                }

                return parent::dispatch($command);
            }
        };

        try {
            (new TimerResumer(
                $this->app->make(ConnectionResolverInterface::class),
                $this->app->make(FlowStore::class),
                static fn (): \DateTimeImmutable => Carbon::now()->toDateTimeImmutable(),
                $bus,
            ))->resume($runId, 't');
            $this->fail('the enqueue failure must surface');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('succeeded', $this->node($runId, 't')->status);
        $this->assertSame('running', DB::table('flow_runs')->where('id', $runId)->value('status'));

        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(61 + 61));
        $this->artisan('flow:resume-due-timers')->expectsOutput('1 due timer(s) dispatched.')->assertExitCode(0);

        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
    }

    public function test_a_synchronous_timer_is_stamped_after_the_inline_wait(): void
    {
        // Make the faked sleep advance the clock, as a real one would.
        Sleep::whenFakingSleep(static function ($duration): void {
            Carbon::setTestNow(Carbon::now()->add($duration));
        });

        $result = $this->runner()->run($this->timerThenProbe(3), []);

        $row = $this->node($result->runId, 't');
        $this->assertGreaterThanOrEqual(3000, (int) $row->duration_ms);
        $this->assertGreaterThanOrEqual(
            Carbon::parse($row->started_at)->addSeconds(3)->getTimestamp(),
            Carbon::parse($row->finished_at)->getTimestamp(),
        );
    }

    public function test_a_cancelled_run_never_resumes_its_timer(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);

        $this->engine()->cancel($runId);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(120));

        $this->assertFalse($this->app->make(TimerResumer::class)->resume($runId, 't')->wasResumed());
        $this->artisan('flow:resume-due-timers')->expectsOutput('0 due timer(s) dispatched.')->assertExitCode(0);
        $this->assertSame('failed', $this->node($runId, 't')->status);
        $this->assertSame(0, QueueProbeNode::count('p'));
    }

    public function test_an_approval_pause_is_not_a_timer(): void
    {
        // A paused node with no resume_at (an approval gate) must be invisible to
        // the sweeper and to the resumer.
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);
        DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 't')->update(['resume_at' => null]);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addSeconds(120));

        $this->artisan('flow:resume-due-timers')->expectsOutput('0 due timer(s) dispatched.')->assertExitCode(0);
        $this->assertFalse($this->app->make(TimerResumer::class)->resume($runId, 't')->wasResumed());
        $this->assertSame('paused', $this->node($runId, 't')->status);
    }

    public function test_a_resume_job_that_runs_early_reschedules_itself_capped_to_the_hop_limit(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(5000), []);

        // A real, non-sync driver: only then may the job reschedule itself.
        $config = $this->app->make(ConfigRepository::class);
        $config->set('queue.connections.delayed', ['driver' => 'null']);
        $config->set('queue.default', 'delayed');
        $config->set('laravel-flow.executor.timer_max_job_delay_seconds', 900);
        Bus::fake();

        (new ResumeTimerJob($runId, 't'))->handle(
            $this->app->make(TimerResumer::class),
            $this->app->make(BusDispatcher::class),
            $config,
        );

        Bus::assertDispatched(ResumeTimerJob::class, static fn (ResumeTimerJob $job): bool => $job->delay === 900);
    }

    public function test_a_resume_job_on_the_sync_driver_stops_instead_of_looping(): void
    {
        $runId = $this->engine()->dispatchGraph($this->timerThenProbe(60), []);
        $config = $this->app->make(ConfigRepository::class);
        Bus::fake();

        (new ResumeTimerJob($runId, 't'))->handle(
            $this->app->make(TimerResumer::class),
            $this->app->make(BusDispatcher::class),
            $config,
        );

        Bus::assertNotDispatched(ResumeTimerJob::class);
    }

    public function test_the_sweeper_requires_persistence(): void
    {
        $this->app->make(ConfigRepository::class)->set('laravel-flow.persistence.enabled', false);

        $this->artisan('flow:resume-due-timers')->assertExitCode(1);
    }
}
