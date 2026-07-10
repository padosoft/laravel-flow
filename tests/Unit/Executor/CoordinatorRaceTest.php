<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;
use Mockery;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\Jobs\NodeJob;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * Deterministic safety-net tests for the queued coordinator: real concurrency
 * is not reproducible on the shared-connection sqlite test DB, so each race is
 * simulated by invoking the coordinator/node seam twice against the same state.
 */
final class CoordinatorRaceTest extends PersistenceTestCase
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

    private function coordinator(): QueueGraphCoordinator
    {
        return $this->app->make(QueueGraphCoordinator::class);
    }

    private function nodeRepository(): RunNodeRepository
    {
        return $this->app->make(RunNodeRepository::class);
    }

    public function test_duplicate_coordinator_pass_claims_each_node_once(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('a', 'test.probe'), new GraphNode('b', 'test.probe')],
            [new Connection('a', 'out', 'b', 'in')],
        );

        $coordinator = $this->coordinator();
        $runId = $coordinator->start($graph, [], null, 'graph');

        $first = $coordinator->advance($runId, $graph);
        $second = $coordinator->advance($runId, $graph);

        $this->assertSame(['a'], $first->claimed);
        $this->assertSame([], $second->claimed, 'a second coordinator pass must not re-claim the already-running root');
    }

    public function test_ready_wave_claims_all_independent_nodes_together(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.probe'),
                new GraphNode('b', 'test.probe'),
                new GraphNode('c', 'test.probe'),
            ],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
            ],
        );

        $coordinator = $this->coordinator();
        $runId = $coordinator->start($graph, [], null, 'graph');

        // Complete the root so its two independent children become ready.
        $this->nodeRepository()->createOrUpdate($runId, 'a', ['node_type' => 'test.probe', 'status' => 'succeeded', 'outputs' => ['out' => ['id' => 'a']]]);

        $decision = $this->coordinator()->advance($runId, $graph);

        $this->assertSame(['b', 'c'], $decision->claimed, 'both independent children claimed in one wave, topological order');
    }

    public function test_claim_is_an_exclusive_compare_and_set(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.probe')], []);
        $runId = $this->coordinator()->start($graph, [], null, 'graph');

        $now = new \DateTimeImmutable;

        $this->assertTrue($this->nodeRepository()->claim($runId, 'a', $now));
        $this->assertFalse($this->nodeRepository()->claim($runId, 'a', $now), 'a node can be claimed only once');
        $this->assertSame('running', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
    }

    public function test_duplicate_node_job_does_not_re_execute(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.probe')], []);
        $runId = $this->coordinator()->start($graph, [], null, 'graph');

        $this->app->call([$this->nodeJob($runId, 'a', $graph), 'handle']);
        $this->app->call([$this->nodeJob($runId, 'a', $graph), 'handle']);

        $this->assertSame(1, QueueProbeNode::count('a'), 'a duplicate node job must not re-run the handler');
        $this->assertSame('succeeded', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
    }

    public function test_node_job_that_cannot_take_the_lock_releases_without_executing(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.probe')], []);
        $runId = $this->coordinator()->start($graph, [], null, 'graph');

        $job = $this->nodeJob($runId, 'a', $graph);

        $store = $this->app['cache']->store()->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock($job->lockKey(), 30);
        $this->assertTrue($lock->get());

        try {
            $this->app->call([$job, 'handle']);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, QueueProbeNode::count('a'), 'a node whose lock is held must not execute');
        $this->assertSame('pending', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'));
    }

    public function test_dispatch_failure_releases_claim_so_a_retry_can_redispatch(): void
    {
        $graph = new GraphDefinition([new GraphNode('a', 'test.probe')], []);
        $coordinator = $this->coordinator();
        $runId = $coordinator->start($graph, [], null, 'graph');

        // A queue backend that throws when enqueueing the node job: the claim
        // (pending -> running) has already committed, so the coordinator must
        // release it back to pending for a retry rather than stranding it.
        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));

        $job = new CoordinatorJob(runId: $runId, graph: $graph, definitionName: 'graph', input: []);

        try {
            $job->handle($coordinator, $bus);
            $this->fail('expected the dispatch failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue down', $e->getMessage());
        }

        $this->assertSame('pending', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'a')->value('status'), 'an undispatched claim must be released back to pending');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function nodeJob(string $runId, string $nodeId, GraphDefinition $graph, array $input = []): NodeJob
    {
        return new NodeJob(
            runId: $runId,
            nodeId: $nodeId,
            graph: $graph,
            definitionName: 'graph',
            input: $input,
        );
    }
}
