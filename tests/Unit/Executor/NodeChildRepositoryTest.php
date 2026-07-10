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

    public function test_pending_lifecycle_record_activate_complete(): void
    {
        $repo = $this->repository();
        $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, ['value' => 1]);

        $pending = $repo->nextPending('parent-run', 'fanout');
        $this->assertNotNull($pending);
        $this->assertSame('pending', $pending->status);
        $this->assertSame(1, $repo->countUnfinished('parent-run', 'fanout'));

        $this->assertTrue($repo->activate('parent-run', 'fanout', 0, 'child-0', new \DateTimeImmutable));
        $this->assertNull($repo->nextPending('parent-run', 'fanout'), 'the row is no longer pending once activated');
        $this->assertNotNull($repo->findByChildRun('child-0'));
        $this->assertSame(1, $repo->countUnfinished('parent-run', 'fanout'), 'a running child is still unfinished');

        $this->assertTrue($repo->completeChild('child-0', 'succeeded', ['out' => 2], new \DateTimeImmutable));
        $this->assertFalse($repo->completeChild('child-0', 'failed', null, new \DateTimeImmutable), 'a child completes at most once');
        $this->assertSame(0, $repo->countUnfinished('parent-run', 'fanout'));
    }

    public function test_next_pending_is_ordered_by_child_index(): void
    {
        $repo = $this->repository();
        $repo->recordPending('parent-run', 'fanout', 2, 'doubler', null, []);
        $repo->recordPending('parent-run', 'fanout', 0, 'doubler', null, []);
        $repo->recordPending('parent-run', 'fanout', 1, 'doubler', null, []);

        $this->assertSame(0, $repo->nextPending('parent-run', 'fanout')?->child_index);
        $repo->activate('parent-run', 'fanout', 0, 'child-0', new \DateTimeImmutable);
        $this->assertSame(1, $repo->nextPending('parent-run', 'fanout')?->child_index);
        $this->assertSame(3, $repo->countUnfinished('parent-run', 'fanout'));
    }
}
