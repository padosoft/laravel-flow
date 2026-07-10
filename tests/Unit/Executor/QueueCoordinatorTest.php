<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class QueueCoordinatorTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [QueueProbeNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
    }

    private function engine(): FlowEngine
    {
        return $this->app->make(FlowEngine::class);
    }

    public function test_linear_chain_runs_each_node_once_and_finalizes_succeeded(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.probe'),
                new GraphNode('b', 'test.probe'),
                new GraphNode('c', 'test.probe'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('b', 'out', 'c', 'in'),
            ],
        );

        $runId = $this->engine()->dispatchGraph($graph, []);

        foreach (['a', 'b', 'c'] as $id) {
            $this->assertSame(1, QueueProbeNode::count($id), "node {$id} must run exactly once");
            $this->assertSame('succeeded', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', $id)->value('status'));
        }

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(3, (int) $run->nodes_total);
        $this->assertSame(3, (int) $run->nodes_completed);
        $this->assertSame(0, (int) $run->nodes_failed);
        $this->assertSame('graph', $run->engine);
        $this->assertNotNull($run->finished_at);
    }

    public function test_parallel_independent_nodes_both_run_in_the_same_graph(): void
    {
        // a fans out to b and c; both must execute (the coordinator claims both
        // in one wave), then d joins after both.
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.probe'),
                new GraphNode('b', 'test.probe'),
                new GraphNode('c', 'test.probe'),
                new GraphNode('d', 'test.probe'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
                new Connection('b', 'out', 'd', 'in'),
            ],
        );

        $runId = $this->engine()->dispatchGraph($graph, []);

        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->assertSame(1, QueueProbeNode::count($id), "node {$id} must run exactly once");
        }
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
    }

    public function test_dispatch_graph_seeds_pending_nodes_and_dispatches_the_coordinator(): void
    {
        Queue::fake();

        $graph = new GraphDefinition(
            [new GraphNode('a', 'test.probe'), new GraphNode('b', 'test.probe')],
            [new Connection('a', 'out', 'b', 'in')],
        );

        $runId = $this->engine()->dispatchGraph($graph, []);

        // Run + one pending node row per node committed before any job runs.
        $this->assertSame('running', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(2, DB::table('flow_run_nodes')->where('run_id', $runId)->where('status', 'pending')->count());
        $this->assertSame(0, QueueProbeNode::count('a'));

        Queue::assertPushed(CoordinatorJob::class, 1);
    }

    public function test_dispatch_graph_requires_persistence(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', false);

        $graph = new GraphDefinition([new GraphNode('a', 'test.probe')], []);

        $this->expectExceptionMessage('Queued graph execution requires persistence to be enabled.');
        $this->engine()->dispatchGraph($graph, []);
    }
}
