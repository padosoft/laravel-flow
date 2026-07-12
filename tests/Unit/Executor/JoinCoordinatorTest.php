<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\NodeChildRepository;
use Padosoft\LaravelFlow\Executor\JoinCoordinator;
use Padosoft\LaravelFlow\Executor\JoinResult;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class JoinCoordinatorTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.persistence.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        $this->seedSuspendedFanout(3);
    }

    private function joinCoordinator(): JoinCoordinator
    {
        return $this->app->make(JoinCoordinator::class);
    }

    private function children(): NodeChildRepository
    {
        return $this->app->make(NodeChildRepository::class);
    }

    private function seedSuspendedFanout(int $childCount): void
    {
        DB::table('flow_runs')->insert([
            'id' => 'parent-run',
            'definition_name' => 'graph',
            'status' => 'running',
            'engine' => 'graph',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('flow_run_nodes')->insert([
            'run_id' => 'parent-run',
            'node_id' => 'fanout',
            'node_type' => 'flow.foreach',
            'status' => 'paused',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 0; $i < $childCount; $i++) {
            // All children already spawned + running (no pending window) so the
            // join tests exercise completion + resume without needing a published
            // child flow to release the next pending item.
            $this->children()->recordPending('parent-run', 'fanout', $i, 'doubler', null, []);
            $this->children()->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable);
            $this->children()->attachChildRun('parent-run', 'fanout', $i, "child-{$i}");
        }
    }

    public function test_parent_resumes_once_when_last_child_completes(): void
    {
        $this->assertNull($this->joinCoordinator()->childCompleted('child-0', 'succeeded', ['out' => 'a']));
        $this->assertNull($this->joinCoordinator()->childCompleted('child-1', 'succeeded', ['out' => 'b']));

        $result = $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);

        $this->assertInstanceOf(JoinResult::class, $result);
        $this->assertSame(NodeState::Succeeded, $result->parentState);
        $this->assertSame([['out' => 'a'], ['out' => 'b'], ['out' => 'c']], $result->outputs);

        $node = DB::table('flow_run_nodes')->where('run_id', 'parent-run')->where('node_id', 'fanout')->first();
        $this->assertSame('succeeded', $node->status);
        $this->assertSame(['results' => [['out' => 'a'], ['out' => 'b'], ['out' => 'c']]], json_decode((string) $node->outputs, true));
    }

    public function test_concurrent_final_children_do_not_double_resume_parent(): void
    {
        $this->joinCoordinator()->childCompleted('child-0', 'succeeded', ['out' => 'a']);
        $this->joinCoordinator()->childCompleted('child-1', 'succeeded', ['out' => 'b']);

        $first = $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);
        // A redelivered completion of the SAME final child must not RESUME
        // again (no second resumeParent() call, no re-mutated node row) — but
        // it MAY still return a reconstructed JoinResult (read-only, from the
        // already-flipped node's persisted state), so a caller that crashed
        // before dispatching its coordinator job on the first call can retry
        // the dispatch on redelivery. See test_recovers_a_dispatch_that_never_
        // happened_after_the_parent_node_already_resolved below.
        $secondFinishedAt = DB::table('flow_run_nodes')->where('run_id', 'parent-run')->where('node_id', 'fanout')->value('finished_at');
        $second = $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);
        $thirdFinishedAt = DB::table('flow_run_nodes')->where('run_id', 'parent-run')->where('node_id', 'fanout')->value('finished_at');

        $this->assertInstanceOf(JoinResult::class, $first);
        $this->assertInstanceOf(JoinResult::class, $second, 'a duplicate reconstructs the already-resolved result, it does not signal "nothing happened"');
        $this->assertSame($first->parentState, $second->parentState);
        $this->assertSame($first->outputs, $second->outputs);
        $this->assertSame($secondFinishedAt, $thirdFinishedAt, 'the node row was never re-mutated by the duplicate — resumeParent() ran exactly once');
    }

    public function test_recovers_a_dispatch_that_never_happened_after_the_parent_node_already_resolved(): void
    {
        // Crash-window recovery (the P1 gap): the FIRST call's resumeParent()
        // already flipped the parent node terminal, but the caller then
        // crashed BEFORE dispatching a coordinator job for the resumable
        // parent run — nothing else would ever re-trigger that dispatch. A
        // redelivery of the SAME final child completion must reconstruct the
        // JoinResult from the parent node's durable state (not the one-shot
        // CAS outcome), so QueueGraphCoordinator::resumeParentIfChild() can
        // retry the dispatch.
        $this->joinCoordinator()->childCompleted('child-0', 'succeeded', ['out' => 'a']);
        $this->joinCoordinator()->childCompleted('child-1', 'succeeded', ['out' => 'b']);
        $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);

        // Parent run is stuck exactly as a crashed dispatch would leave it:
        // the node is terminal, the run itself never advanced past `running`.
        $this->assertSame('succeeded', DB::table('flow_run_nodes')->where('run_id', 'parent-run')->where('node_id', 'fanout')->value('status'));
        $this->assertSame('running', DB::table('flow_runs')->where('id', 'parent-run')->value('status'));

        $recovered = $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);

        $this->assertInstanceOf(JoinResult::class, $recovered, 'the retry recovers a dispatchable JoinResult instead of a silent null');
        $this->assertSame('parent-run', $recovered->parentRunId);
        $this->assertSame(NodeState::Succeeded, $recovered->parentState);
        $this->assertSame([['out' => 'a'], ['out' => 'b'], ['out' => 'c']], $recovered->outputs);
    }

    public function test_duplicate_of_a_still_pending_join_returns_null(): void
    {
        // The genuine "concurrent duplicate, original in-flight call drives
        // it" case must stay null: with siblings still outstanding, the
        // parent node was never flipped, so there is nothing to reconstruct.
        $this->joinCoordinator()->childCompleted('child-0', 'succeeded', ['out' => 'a']);
        $duplicate = $this->joinCoordinator()->childCompleted('child-0', 'succeeded', ['out' => 'a']);

        $this->assertNull($duplicate);
    }

    public function test_child_failure_aggregates_into_parent(): void
    {
        $this->joinCoordinator()->childCompleted('child-0', 'succeeded', ['out' => 'a']);
        $this->joinCoordinator()->childCompleted('child-1', 'failed', null);

        $result = $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);

        $this->assertInstanceOf(JoinResult::class, $result);
        $this->assertSame(NodeState::Failed, $result->parentState, 'any failed child fails the parent join');
        $this->assertSame('failed', DB::table('flow_run_nodes')->where('run_id', 'parent-run')->where('node_id', 'fanout')->value('status'));
    }
}
