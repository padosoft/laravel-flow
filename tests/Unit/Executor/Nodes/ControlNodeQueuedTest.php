<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\Nodes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\DoubleNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * Queued-executor coverage of the fan-out control nodes: a ForEach node spawns
 * one child graph run per item (async), suspends the parent, and the join
 * coordinator resumes the parent once every child terminates — driven through a
 * real database queue so children run asynchronously (not re-entrantly). Also
 * pins the sync-vs-queued equivalence oracle.
 */
final class ControlNodeQueuedTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'after_commit' => false,
            'connection' => 'testing',
            'driver' => 'database',
            'queue' => 'default',
            'retry_after' => 90,
            'table' => 'jobs',
        ]);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        // A shared (non process-local) lock store is required off the sync queue.
        $app['config']->set('laravel-flow.executor.lock_store', 'file');
        $app['config']->set('laravel-flow.nodes.handlers', [DoubleNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        DoubleNode::$invocations = 0;
        $this->createJobsTable();

        $this->publishChild('doubler', new GraphDefinition([new GraphNode('d', 'test.double')], []));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        parent::tearDown();
    }

    private function createJobsTable(): void
    {
        Schema::dropIfExists('jobs');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function publishChild(string $name, GraphDefinition $graph): void
    {
        $repository = $this->app->make(DefinitionRepository::class);
        $stored = $repository->createDraft($name, $graph);
        $repository->publish($name, $stored->version);
    }

    private function drainQueue(): void
    {
        for ($i = 0; $i < 200 && DB::table('jobs')->count() > 0; $i++) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);
        }

        $this->assertSame(0, DB::table('jobs')->count(), 'the queue must drain');
    }

    public function test_queued_foreach_suspends_and_resumes_parent_via_join(): void
    {
        $graph = new GraphDefinition([
            new GraphNode('fe', 'flow.foreach', ['flow' => 'doubler', 'maxConcurrency' => 2, 'items' => [1, 2, 3]]),
        ], []);

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);

        // Before draining, the parent node was suspended (paused) and children spawned.
        $this->drainQueue();

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('succeeded', $run->status, 'the parent run resumes and completes after the join');

        $feNode = DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'fe')->first();
        $this->assertSame('succeeded', $feNode->status);
        $this->assertSame([
            'results' => [
                ['d' => ['doubled' => 2]],
                ['d' => ['doubled' => 4]],
                ['d' => ['doubled' => 6]],
            ],
        ], json_decode((string) $feNode->outputs, true), 'ordered per-child outputs joined into results');

        // One child run per item plus the parent run.
        $this->assertSame(3, DoubleNode::$invocations);
        $this->assertSame(3, DB::table('flow_node_children')->where('run_id', $runId)->where('parent_node_id', 'fe')->count());
    }

    public function test_sync_and_queued_foreach_produce_identical_results(): void
    {
        $graph = new GraphDefinition([
            new GraphNode('fe', 'flow.foreach', ['flow' => 'doubler', 'items' => [1, 2, 3]]),
        ], []);

        // Synchronous path.
        $syncResult = $this->app->make(GraphRunner::class)->run($graph, []);
        $syncResults = $syncResult->nodeOutputs['fe']['results'];

        // Queued path.
        $queuedRunId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);
        $this->drainQueue();
        $queuedOutputs = json_decode((string) DB::table('flow_run_nodes')->where('run_id', $queuedRunId)->where('node_id', 'fe')->value('outputs'), true);

        $this->assertSame($syncResults, $queuedOutputs['results'], 'the sync and queued fan-out paths agree on aggregated output + ordering');
    }
}
