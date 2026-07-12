<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\RetryAlwaysFailNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\RetryFlakyNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class RetryExecutionTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [RetryFlakyNode::class, RetryAlwaysFailNode::class, FailingGraphNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        RetryFlakyNode::$calls = 0;
        RetryAlwaysFailNode::$calls = 0;
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    public function test_fail_twice_then_succeed_with_three_tries(): void
    {
        $result = $this->runner()->run(new GraphDefinition([new GraphNode('f', 'test.flaky')], []), []);

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['f']);
        $this->assertSame(3, RetryFlakyNode::$calls);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'f')->first();
        $this->assertSame(3, (int) $row->attempts);
    }

    public function test_exhausted_retries_dead_letter_the_node_and_advance_available_at(): void
    {
        Date::setTestNow(Carbon::parse('2026-05-03 12:00:00'));

        $result = $this->runner()->run(new GraphDefinition([new GraphNode('x', 'test.alwaysfail')], []), []);

        $this->assertSame(NodeState::DeadLetter, $result->nodeStates['x']);
        $this->assertSame(RunState::Failed, $result->state);
        $this->assertSame(2, RetryAlwaysFailNode::$calls);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'x')->first();
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame('dead_letter', $row->status);
        // available_at advanced by the 5s backoff scheduled before the last attempt.
        $this->assertNotNull($row->available_at);
        $this->assertSame(
            Carbon::parse('2026-05-03 12:00:05')->timestamp,
            Carbon::parse($row->available_at)->timestamp,
        );
    }

    public function test_dry_run_does_not_sleep_between_retries(): void
    {
        // A dry run of a failing retry node must not delay the process.
        $this->app->make(FlowEngine::class)
            ->dryRunGraph(new GraphDefinition([new GraphNode('x', 'test.alwaysfail')], []), []);

        Sleep::assertNeverSlept();
    }

    public function test_config_retry_override_reduces_tries(): void
    {
        // Override the flaky node (attribute tries:3) down to tries:1 via config:
        // it fails on the single attempt (no retry budget → Failed, not dead-letter).
        $graph = new GraphDefinition([new GraphNode('f', 'test.flaky', ['retry' => ['tries' => 1]])], []);

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Failed, $result->nodeStates['f']);
        $this->assertSame(1, RetryFlakyNode::$calls);
    }

    public function test_executor_default_tries_applies_to_a_node_with_no_retry_attribute(): void
    {
        // FailingGraphNode declares no #[Retry] at all: with the historical
        // hard-coded fallback (tries=1) it would fail on the first attempt. With
        // laravel-flow.executor.default_tries configured, the graph-wide default
        // gives it a real retry budget instead.
        $this->app['config']->set('laravel-flow.executor.default_tries', 3);
        $this->app['config']->set('laravel-flow.executor.default_backoff_seconds', 0);

        $result = $this->runner()->run(new GraphDefinition([new GraphNode('f', 'test.fail')], []), []);

        $this->assertSame(NodeState::DeadLetter, $result->nodeStates['f'], 'a real retry budget (tries>1) that exhausts dead-letters');

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'f')->first();
        $this->assertSame(3, (int) $row->attempts);
    }

    public function test_a_nodes_own_retry_config_still_overrides_the_executor_default(): void
    {
        $this->app['config']->set('laravel-flow.executor.default_tries', 5);

        $graph = new GraphDefinition([new GraphNode('f', 'test.fail', ['retry' => ['tries' => 2]])], []);
        $result = $this->runner()->run($graph, []);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'f')->first();
        $this->assertSame(2, (int) $row->attempts, "the node's own config override wins over the executor-wide default");
    }

    public function test_stock_executor_defaults_preserve_the_historical_single_attempt_behavior(): void
    {
        // With NO executor.* env overrides (the package's shipped defaults:
        // default_tries=1, default_backoff_seconds=0), a #[Retry]-less node's
        // observable behavior is unchanged: one attempt, plain Failed.
        $result = $this->runner()->run(new GraphDefinition([new GraphNode('f', 'test.fail')], []), []);

        $this->assertSame(NodeState::Failed, $result->nodeStates['f']);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'f')->first();
        $this->assertSame(1, (int) $row->attempts);
    }
}
