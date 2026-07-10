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

    public function test_record_creates_a_running_child_row(): void
    {
        $now = new \DateTimeImmutable;
        $record = $this->repository()->record('parent-run', 'fanout', 'child-0', 0, $now);

        $this->assertSame('running', $record->status);
        $this->assertSame(0, $record->child_index);
        $this->assertSame('child-0', $record->child_run_id);
        $this->assertNotNull($this->repository()->findByChildRun('child-0'));
        $this->assertNull($this->repository()->findByChildRun('nope'));
    }

    public function test_complete_child_is_an_idempotent_compare_and_set(): void
    {
        $this->repository()->record('parent-run', 'fanout', 'child-0', 0, new \DateTimeImmutable);

        $this->assertTrue($this->repository()->completeChild('child-0', 'succeeded', ['out' => 1], new \DateTimeImmutable));
        $this->assertFalse($this->repository()->completeChild('child-0', 'failed', ['out' => 2], new \DateTimeImmutable), 'a child completes at most once');

        $row = DB::table('flow_node_children')->where('child_run_id', 'child-0')->first();
        $this->assertSame('succeeded', $row->status);
        $this->assertNotNull($row->finished_at);
    }

    public function test_for_parent_returns_children_ordered_by_index(): void
    {
        $this->repository()->record('parent-run', 'fanout', 'child-2', 2, new \DateTimeImmutable);
        $this->repository()->record('parent-run', 'fanout', 'child-0', 0, new \DateTimeImmutable);
        $this->repository()->record('parent-run', 'fanout', 'child-1', 1, new \DateTimeImmutable);

        $children = $this->repository()->forParent('parent-run', 'fanout');

        $this->assertSame([0, 1, 2], $children->pluck('child_index')->all());
        $this->assertSame(['child-0', 'child-1', 'child-2'], $children->pluck('child_run_id')->all());
    }
}
