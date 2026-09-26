<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\BranchingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * End-to-end branch skip (NodeResult::branch()) through BOTH executors: the
 * synchronous GraphRunner and the queued coordinator (sync queue driver).
 */
final class BranchSkipTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [BranchingNode::class, QueueProbeNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
    }

    /**
     * c --yes--> a --\
     *   \--no--> b ---> m (flow.merge)
     */
    private function diamond(string $take): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('c', 'test.branch', ['take' => $take]),
                new GraphNode('a', 'test.probe'),
                new GraphNode('b', 'test.probe'),
                new GraphNode('m', 'flow.merge'),
            ],
            [
                new Connection('c', 'yes', 'a', 'in'),
                new Connection('c', 'no', 'b', 'in'),
                new Connection('a', 'out', 'm', 'items'),
                new Connection('b', 'out', 'm', 'items'),
            ],
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

    public function test_the_store_reuses_one_run_node_repository_so_its_schema_probe_is_memoised(): void
    {
        // The store is a container singleton but used to build a NEW repository
        // per runNodes() call, discarding the hasColumn memo and re-running that
        // metadata query on every coordinator pass.
        $store = $this->app->make(FlowStore::class);

        $this->assertSame($store->runNodes(), $store->runNodes());
    }

    public function test_sync_only_the_taken_branch_runs_and_the_join_still_runs(): void
    {
        $result = $this->runner()->run($this->diamond('yes'), []);

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['c']);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['a']);
        $this->assertSame(NodeState::Skipped, $result->nodeStates['b']);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['m']);
        $this->assertSame(1, QueueProbeNode::count('a'));
        $this->assertSame(0, QueueProbeNode::count('b'));
        // The dead wire contributes nothing to the join.
        $this->assertSame([['id' => 'a']], $result->nodeOutputs['m']['merged']);
    }

    public function test_sync_inactive_outputs_are_stripped_and_active_ports_persisted(): void
    {
        $result = $this->runner()->run($this->diamond('no'), []);

        $this->assertSame(['no' => ['from' => 'c']], $result->nodeOutputs['c']);

        $rows = DB::table('flow_run_nodes')->where('run_id', $result->runId)->get()->keyBy('node_id');
        $this->assertSame(['no'], json_decode((string) $rows['c']->active_ports, true));
        $this->assertSame([], json_decode((string) $rows['a']->active_ports, true));
        $this->assertSame('skipped', $rows['a']->status);
        // An ordinary node never writes the column.
        $this->assertNull($rows['b']->active_ports);
        $this->assertNull($rows['m']->active_ports);
        $this->assertSame(['no' => ['from' => 'c']], json_decode((string) $rows['c']->outputs, true));
    }

    public function test_sync_skip_propagates_transitively_in_one_run(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('c', 'test.branch', ['take' => 'yes']),
                new GraphNode('b1', 'test.probe'),
                new GraphNode('b2', 'test.probe'),
                new GraphNode('b3', 'test.probe'),
            ],
            [
                new Connection('c', 'no', 'b1', 'in'),
                new Connection('b1', 'out', 'b2', 'in'),
                new Connection('b2', 'out', 'b3', 'in'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(RunState::Succeeded, $result->state);
        foreach (['b1', 'b2', 'b3'] as $id) {
            $this->assertSame(NodeState::Skipped, $result->nodeStates[$id], $id);
            $this->assertSame(0, QueueProbeNode::count($id), $id);
        }
    }

    public function test_sync_both_ports_active_runs_both_branches(): void
    {
        $result = $this->runner()->run($this->diamond('both'), []);

        $this->assertSame(RunState::Succeeded, $result->state);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['a']);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['b']);
        $this->assertCount(2, $result->nodeOutputs['m']['merged']);
    }

    public function test_sync_a_node_whose_every_incoming_wire_is_dead_is_skipped_but_a_mixed_join_runs(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('c1', 'test.branch', ['take' => 'yes']),
                new GraphNode('c2', 'test.branch', ['take' => 'yes']),
                new GraphNode('live', 'test.probe'),
                new GraphNode('allDead', 'flow.merge'),
                new GraphNode('mixed', 'flow.merge'),
            ],
            [
                new Connection('c1', 'no', 'allDead', 'items'),
                new Connection('c2', 'no', 'allDead', 'items'),
                new Connection('c1', 'no', 'mixed', 'items'),
                new Connection('live', 'out', 'mixed', 'items'),
            ],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Skipped, $result->nodeStates['allDead']);
        $this->assertSame(NodeState::Succeeded, $result->nodeStates['mixed']);
        $this->assertSame([['id' => 'live']], $result->nodeOutputs['mixed']['merged']);
        $this->assertSame(RunState::Succeeded, $result->state);
    }

    public function test_sync_an_ordinary_node_omitting_a_port_keeps_the_historical_behaviour(): void
    {
        // `plain` is NOT a branch: it succeeds without emitting `no`, and the
        // node wired to `no` must still run (its input is merely absent).
        $graph = new GraphDefinition(
            [
                new GraphNode('c', 'test.branch', ['take' => 'plain']),
                new GraphNode('b', 'test.probe'),
            ],
            [new Connection('c', 'no', 'b', 'in')],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['b']);
        $this->assertSame(1, QueueProbeNode::count('b'));
        $this->assertNull(DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'c')->value('active_ports'));
    }

    public function test_sync_activating_an_undeclared_port_fails_the_node_and_blocks_downstream(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('c', 'test.branch', ['take' => 'ghost']),
                new GraphNode('a', 'test.probe'),
            ],
            [new Connection('c', 'yes', 'a', 'in')],
        );

        $result = $this->runner()->run($graph, []);

        $this->assertSame(NodeState::Failed, $result->nodeStates['c']);
        $this->assertSame(NodeState::Blocked, $result->nodeStates['a']);
        $this->assertStringContainsString('undeclared output port(s) [ghost]', $result->errors['c']);
        $this->assertSame(0, QueueProbeNode::count('a'));
    }

    public function test_dry_run_evaluates_the_branch_and_writes_nothing(): void
    {
        $result = $this->runner()->run($this->diamond('yes'), [], null, true);

        $this->assertSame(NodeState::Succeeded, $result->nodeStates['a']);
        $this->assertSame(NodeState::Skipped, $result->nodeStates['b']);
        $this->assertSame(0, DB::table('flow_run_nodes')->count());
    }

    public function test_queued_only_the_taken_branch_runs_and_the_run_succeeds(): void
    {
        $runId = $this->engine()->dispatchGraph($this->diamond('yes'), []);

        $status = static fn (string $id): string => (string) DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', $id)->value('status');

        $this->assertSame('succeeded', $status('c'));
        $this->assertSame('succeeded', $status('a'));
        $this->assertSame('skipped', $status('b'));
        $this->assertSame('succeeded', $status('m'));
        $this->assertSame(0, QueueProbeNode::count('b'));
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
    }

    public function test_queued_skip_propagates_and_run_finalizes(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('c', 'test.branch', ['take' => 'yes']),
                new GraphNode('b1', 'test.probe'),
                new GraphNode('b2', 'test.probe'),
            ],
            [
                new Connection('c', 'no', 'b1', 'in'),
                new Connection('b1', 'out', 'b2', 'in'),
            ],
        );

        $runId = $this->engine()->dispatchGraph($graph, []);

        $this->assertSame(2, DB::table('flow_run_nodes')->where('run_id', $runId)->where('status', 'skipped')->count());
        $this->assertSame('succeeded', DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(0, QueueProbeNode::count('b1') + QueueProbeNode::count('b2'));
    }
}
