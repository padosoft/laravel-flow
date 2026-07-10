<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\NodeChildRepository;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

final class NodeChildRepositoryTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        $this->seedParentRun();
    }

    private function repository(): NodeChildRepository
    {
        return $this->app->make(NodeChildRepository::class);
    }

    private function seedParentRun(): void
    {
        DB::table('flow_runs')->insert([
            'id' => 'parent-run',
            'definition_name' => 'graph',
            'status' => 'running',
            'engine' => 'graph',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pending_lifecycle_claim_attach_complete(): void
    {
        $repo = $this->repository();
        $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, ['value' => 1]);
        $this->assertSame(1, $repo->countUnfinished('parent-run', 'fanout'));

        $claimed = $repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable);
        $this->assertNotNull($claimed);
        $this->assertSame(0, $claimed->child_index);
        $this->assertSame('running', $claimed->status, 'claimed BEFORE dispatch, no child run id yet');
        $this->assertNull($claimed->child_run_id);

        $repo->attachChildRun('parent-run', 'fanout', 0, 'child-0');
        $this->assertNotNull($repo->findByChildRun('child-0'));
        $this->assertSame(1, $repo->countUnfinished('parent-run', 'fanout'), 'a running child is still unfinished');

        $this->assertTrue($repo->completeChild('child-0', 'succeeded', ['out' => 2], new \DateTimeImmutable));
        $this->assertFalse($repo->completeChild('child-0', 'failed', null, new \DateTimeImmutable), 'a child completes at most once');
        $this->assertSame(0, $repo->countUnfinished('parent-run', 'fanout'));
    }

    public function test_claim_next_pending_is_exclusive_and_ordered(): void
    {
        // The claim (not any cache lock) is what prevents two spawners from
        // picking the same slot: each call claims a DISTINCT lowest-index pending
        // row, in order, and returns null once none remain.
        $repo = $this->repository();
        $repo->recordPending('parent-run', 'fanout', 2, 'doubler', null, []);
        $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, []);
        $repo->recordPending('parent-run', 'fanout', 1, 'doubler', null, []);

        $indices = [
            $repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable)?->child_index,
            $repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable)?->child_index,
            $repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable)?->child_index,
        ];

        $this->assertSame([0, 1, 2], $indices, 'each claim takes the next distinct pending row in index order');
        $this->assertNull($repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable), 'nothing left to claim');
        $this->assertSame(3, $repo->countUnfinished('parent-run', 'fanout'), 'all three now running');
    }

    public function test_record_pending_is_idempotent(): void
    {
        // A retried control-node execution re-records items; it must be a no-op,
        // not throw on the (run_id, parent_node_id, child_index) unique key.
        $repo = $this->repository();
        $first = $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, ['value' => 1]);
        $again = $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, ['value' => 1]);

        $this->assertSame($first->id, $again->id, 'the same ledger row is returned, not a duplicate');
        $this->assertSame(1, DB::table('flow_node_children')->where('run_id', 'parent-run')->where('child_index', 0)->count());
    }

    public function test_count_running_tracks_in_flight_children(): void
    {
        $repo = $this->repository();
        $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, []);
        $repo->recordPending('parent-run', 'fanout', 1, 'doubler', null, []);
        $repo->recordPending('parent-run', 'fanout', 2, 'doubler', null, []);

        $this->assertSame(0, $repo->countRunning('parent-run', 'fanout'));
        $repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable);
        $repo->claimNextPending('parent-run', 'fanout', new \DateTimeImmutable);
        $this->assertSame(2, $repo->countRunning('parent-run', 'fanout'), 'two claimed = two running');
        $this->assertSame(3, $repo->countUnfinished('parent-run', 'fanout'));
    }
}
