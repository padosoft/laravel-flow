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
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\RetryAlwaysFailNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\RetryFlakyNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class RetryExecutionTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [RetryFlakyNode::class, RetryAlwaysFailNode::class]);
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

    public function test_config_retry_override_reduces_tries(): void
    {
        // Override the flaky node (attribute tries:3) down to tries:1 via config:
        // it fails on the single attempt (no retry budget → Failed, not dead-letter).
        $graph = new GraphDefinition([new GraphNode('f', 'test.flaky', ['retry' => ['tries' => 1]])], []);

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Failed, $result->nodeStates['f']);
        $this->assertSame(1, RetryFlakyNode::$calls);
    }
}
