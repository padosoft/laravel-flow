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
            $this->children()->activate('parent-run', 'fanout', $i, "child-{$i}", new \DateTimeImmutable);
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
        // A redelivered completion of the SAME final child must not resume again.
        $second = $this->joinCoordinator()->childCompleted('child-2', 'succeeded', ['out' => 'c']);

        $this->assertInstanceOf(JoinResult::class, $first);
        $this->assertNull($second, 'the parent must resume exactly once');
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
