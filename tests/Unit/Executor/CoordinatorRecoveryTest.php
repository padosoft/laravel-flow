<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Executor\Jobs\NodeJob;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\PausingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class CoordinatorRecoveryTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [QueueProbeNode::class, FailingGraphNode::class, PausingGraphNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
        PausingGraphNode::$invocations = 0;
    }

    private function coordinator(): QueueGraphCoordinator
    {
        return $this->app->make(QueueGraphCoordinator::class);
    }

    public function test_orphaned_running_node_is_not_double_executed(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.probe')], []);
        $coordinator = $this->coordinator();
        $runId = $coordinator->start($graph, [], null, 'graph');

        // Simulate a node claimed by a worker that then died mid-flight: the row
        // is `running` and the worker's lock is still held (within its TTL).
        $this->assertTrue($this->app->make(RunNodeRepository::class)->claim($runId, 'a', new \DateTimeImmutable));

        $job = new NodeJob(runId: $runId, nodeId: 'a', graph: $graph, definitionName: 'graph', input: []);
        $store = $this->app['cache']->store()->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock($job->lockKey(), 30);
        $this->assertTrue($lock->get());

        try {
            $this->app->call([$job, 'handle']);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, QueueProbeNode::count('a'), 'an orphaned running node whose lock is held must not be re-executed');
        $this->assertSame('running', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
    }

    public function test_paused_node_is_not_re_executed_by_a_duplicate_job(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.pause')], []);
        $coordinator = $this->coordinator();
        $runId = $coordinator->start($graph, [], null, 'graph');
        $this->app->make(RunNodeRepository::class)->claim($runId, 'a', new \DateTimeImmutable);

        // First job pauses the node.
        $this->app->call([new NodeJob(runId: $runId, nodeId: 'a', graph: $graph, definitionName: 'graph', input: []), 'handle']);
        $this->assertSame('paused', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
        $this->assertSame(1, PausingGraphNode::$invocations);

        // A duplicate/retried job must NOT re-enter the handler on a paused node.
        $this->app->call([new NodeJob(runId: $runId, nodeId: 'a', graph: $graph, definitionName: 'graph', input: []), 'handle']);
        $this->assertSame(1, PausingGraphNode::$invocations, 'a paused node must not be re-executed');
    }

    public function test_run_finalizes_when_all_nodes_terminal(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('a', 'test.probe'), new GraphNode('b', 'test.probe')],
            [new Connection('a', 'out', 'b', 'in')],
        );

        $coordinator = $this->coordinator();
        $runId = $coordinator->start($graph, [], null, 'graph');

        $repository = $this->app->make(RunNodeRepository::class);
        $repository->createOrUpdate($runId, 'a', ['node_type' => 'test.probe', 'status' => 'succeeded']);
        $repository->createOrUpdate($runId, 'b', ['node_type' => 'test.probe', 'status' => 'succeeded']);

        $decision = $coordinator->advance($runId, $graph);

        $this->assertTrue($decision->allTerminal);
        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(2, (int) $run->nodes_completed);
        $this->assertNotNull($run->finished_at);
    }

    public function test_paused_node_finalizes_run_as_paused(): void
    {
        // A node that pauses settles the run with no ready/blocked/running work
        // left; the coordinator must finalize it as `paused` rather than leaving
        // it hanging in `running` forever.
        $graph = new GraphDefinition([new GraphNode('a', 'test.pause')], []);

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);

        $this->assertSame('paused', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('paused', $run->status);
        $this->assertNull($run->finished_at, 'a paused run is not finished');
    }

    public function test_blocked_run_finalizes_partially_succeeded(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.probe'),
                new GraphNode('f', 'test.fail'),
                new GraphNode('d', 'test.probe'),
            ],
            [
                new Connection('a', 'out', 'f', 'in'),
                new Connection('f', 'out', 'd', 'in'),
            ],
        );

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);

        $this->assertSame('succeeded', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
        $this->assertSame('failed', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'f')->value('status'));
        $this->assertSame('blocked', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'd')->value('status'));

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('partially_succeeded', $run->status);
        $this->assertNotNull($run->finished_at);
    }
}
