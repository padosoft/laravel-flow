<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\InvocationRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\JsonEmitNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\PassThroughNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class GraphRunnerTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [
            PassThroughNode::class,
            FailingGraphNode::class,
            JsonEmitNode::class,
            InvocationRecordingNode::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        InvocationRecordingNode::$invocations = 0;
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    public function test_diamond_runs_all_nodes_in_dependency_order(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.jsonemit'),
                new GraphNode('b', 'test.pass'),
                new GraphNode('c', 'test.pass'),
                new GraphNode('d', 'flow.merge'),
            ],
            [
                new Connection('a', 'data', 'b', 'in'),
                new Connection('a', 'data', 'c', 'in'),
                new Connection('b', 'out', 'd', 'items'),
                new Connection('c', 'out', 'd', 'items'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(RunState::Succeeded, $result->state);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->assertSame(NodeState::Succeeded, $result->nodeStates[$id], $id);
        }
        $this->assertSame(['id' => 'a'], $result->nodeOutputs['b']['out']);
        $this->assertSame([['id' => 'a'], ['id' => 'a']], $result->nodeOutputs['d']['merged']);
    }

    public function test_failed_node_blocks_downstream_and_run_is_partially_succeeded(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.jsonemit'),
                new GraphNode('f', 'test.fail'),
                new GraphNode('d', 'test.pass'),
            ],
            [
                new Connection('a', 'data', 'f', 'in'),
                new Connection('f', 'out', 'd', 'in'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['a']);
        $this->assertSame(NodeState::Failed, $result->nodeStates['f']);
        $this->assertSame(NodeState::Blocked, $result->nodeStates['d']);
        $this->assertSame(RunState::PartiallySucceeded, $result->state);
    }

    public function test_invalid_input_short_circuits_before_handler(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('r', 'test.record')], // required 'required' input is unwired + no config
            [],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::InvalidInput, $result->nodeStates['r']);
        $this->assertSame(0, InvocationRecordingNode::$invocations, 'handler must not run on invalid input');
        $this->assertSame(RunState::Failed, $result->state);
    }

    public function test_per_node_timing_is_recorded(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.jsonemit')], []);

        $result = $this->runner()->run($graph, []);

        $row = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'a')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->finished_at);
        $this->assertNotNull($row->duration_ms);
        $this->assertGreaterThanOrEqual(0, (int) $row->duration_ms);
    }

    public function test_dry_run_writes_zero_rows(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.jsonemit')], []);

        $this->app->make(FlowEngine::class)->dryRunGraph($graph, []);

        $this->assertSame(0, DB::table('flow_runs')->count());
        $this->assertSame(0, DB::table('flow_run_nodes')->count());
        $this->assertSame(0, DB::table('flow_audit')->count());
    }
}
