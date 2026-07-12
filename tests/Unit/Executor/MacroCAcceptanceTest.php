<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Executor\DryRun\DryRunPlanner;
use Padosoft\LaravelFlow\Executor\Jobs\NodeJob;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CompensatableRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\DoubleNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FailingGraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * Macro C Gate (G3.2) acceptance checklist, verified with executable evidence
 * rather than asserted by narrative: "a nested, fan-out, approval-gated graph
 * runs green on queue with induced duplicate dispatches; kill-a-worker-mid-run
 * recovers; full dry-run of the same graph writes nothing; saga compensates a
 * mid-graph failure in correct order."
 *
 * Each clause reuses the SAME "fan-out with nested children, then an
 * approval-gate-shaped downstream" graph, run through the queued coordinator
 * (`QueueGraphCoordinator` — the same seam `Flow::dispatchGraph()` uses), on a
 * REAL database queue (`ControlNodeQueuedTest`'s proven pattern: a `jobs`
 * table drained via `queue:work --once` in a loop — each `Artisan::call()`
 * invocation runs in THIS same PHP process, but pulls its job from the DB
 * queue table rather than executing it inline/recursively) — NOT the `sync`
 * driver. See `docs/LESSON.md` (2026-07-13): fan-out (`maxConcurrency`
 * spawning more than one child) on the fully synchronous/recursive `sync`
 * driver can self-deadlock in `JoinCoordinator::childCompleted()` (confirmed
 * while writing this test), which the real-queue pattern here avoids
 * structurally — every `Bus::dispatch()` the system performs becomes a
 * queue-table row, not a nested call.
 */
final class MacroCAcceptanceTest extends PersistenceTestCase
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
        // A shared (non process-local) lock store is required off the sync queue —
        // both for node execution (executor.*) and approval resume (queue.*).
        $app['config']->set('laravel-flow.executor.lock_store', 'file');
        $app['config']->set('laravel-flow.queue.lock_store', 'file');
        $app['config']->set('laravel-flow.nodes.handlers', [
            QueueProbeNode::class,
            DoubleNode::class,
            CompensatableRecordingNode::class,
            FailingGraphNode::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
        DoubleNode::$invocations = 0;
        CompensatableRecordingNode::reset();
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

    /**
     * Nested (fan-out children are their own child runs) + fan-out (flow.foreach)
     * + approval-gated (flow.approval), wired: fe -> gate -> downstream.
     */
    private function nestedFanoutApprovalGraph(): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('fe', 'flow.foreach', ['flow' => 'doubler', 'maxConcurrency' => 2, 'items' => [1, 2]]),
                new GraphNode('gate', 'flow.approval'),
                new GraphNode('downstream', 'test.probe'),
            ],
            [
                new Connection('fe', 'out', 'gate', 'in'),
                new Connection('gate', 'out', 'downstream', 'in'),
            ],
        );
    }

    public function test_nested_fanout_approval_gated_graph_runs_on_queue_with_induced_duplicate_dispatches(): void
    {
        $coordinator = $this->app->make(QueueGraphCoordinator::class);
        $graph = $this->nestedFanoutApprovalGraph();

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);
        $this->drainQueue();

        $this->assertSame(2, DoubleNode::$invocations, 'each nested fan-out child ran exactly once');

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('paused', $run->status, 'the run is suspended at the approval gate, downstream not yet run');
        $this->assertSame(0, QueueProbeNode::count('downstream'));

        $approval = DB::table('flow_approvals')->where('run_id', $runId)->first();
        $this->assertNotNull($approval, 'a pending approval record exists for the gate');

        // Induced duplicate dispatch: re-advance the SAME (already fully
        // settled) run a second time — mirrors CoordinatorRaceTest's pattern.
        $duplicate = $coordinator->advance($runId, $graph);
        $this->assertSame([], $duplicate->claimed, 'a duplicate coordinator pass claims nothing new');
        $this->assertSame(2, DoubleNode::$invocations, 'the duplicate pass did not re-run any nested child');

        // Resume: downstream runs, the run completes. The plain token is
        // never persisted (hash-only storage) — reissue a fresh one for the
        // pending approval, mirroring GraphApprovalResumeTest's own
        // queued-dispatch pattern for capturing a usable token in tests.
        $issued = $this->app->make(ApprovalTokenManager::class)->reissuePendingForStep($runId, 'gate');
        $this->assertNotNull($issued, 'a pending approval exists to reissue a token for');
        $token = $issued->plainTextToken;

        $resumed = $this->app->make(FlowEngine::class)->resume($token, ['decision' => 'ship']);
        $this->drainQueue();

        $this->assertSame(RunState::Succeeded->value, DB::table('flow_runs')->where('id', $runId)->value('status'));
        $this->assertSame(1, QueueProbeNode::count('downstream'));

        // A second, duplicate resume of the same (now-consumed) token must not
        // re-advance the run — a real-world redelivered approval callback. On
        // the real DB queue, resume() can return after enqueueing follow-up
        // jobs but before they execute — drain (asserting the queue ends
        // empty) BEFORE checking the invocation count, or a regression that
        // re-dispatches on an already-consumed token would pass here while
        // leaving duplicate work sitting undetected in `jobs`.
        $duplicateResume = $this->app->make(FlowEngine::class)->resume($token, ['decision' => 'ignored']);
        $this->drainQueue();
        $this->assertSame(RunState::Succeeded->value, $duplicateResume->status);
        $this->assertSame(1, QueueProbeNode::count('downstream'), 'the duplicate resume did not re-run downstream');
    }

    public function test_kill_a_worker_mid_run_recovers(): void
    {
        $graph = $this->nestedFanoutApprovalGraph();
        $runId = $this->app->make(QueueGraphCoordinator::class)->start($graph, [], null, 'graph');

        // Claim 'fe' directly (mirrors CoordinatorRecoveryTest's proven
        // pattern) and hold its per-node lock, simulating a worker that picked
        // it up then died mid-flight.
        $this->assertTrue($this->app->make(RunNodeRepository::class)->claim($runId, 'fe', new DateTimeImmutable));

        $job = new NodeJob(runId: $runId, nodeId: 'fe', graph: $graph, definitionName: 'graph', input: [], lockStore: 'file');
        $lockStore = $this->app['cache']->store('file')->getStore();
        $lock = $lockStore->lock($job->lockKey(), 30);
        $this->assertTrue($lock->get());

        try {
            // A redelivered job for the SAME node, WHILE the "dead" worker's
            // lock is still held, must self-gate — never double-execute.
            $this->app->call([$job, 'handle']);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, DoubleNode::$invocations, 'no execution happened while the lock was held');
        $this->assertSame('running', DB::table('flow_run_nodes')->where('run_id', $runId)->where('node_id', 'fe')->value('status'));

        // The worker is truly gone (lock released). A fresh delivery of the
        // SAME node now recovers it for real: 'fe' spawns its nested children
        // via the REAL queue (queue.default=database — Bus::dispatch() here
        // enqueues rows, it does not execute inline), so draining is what
        // actually runs them and cascades through the join.
        $this->app->call([new NodeJob(runId: $runId, nodeId: 'fe', graph: $graph, definitionName: 'graph', input: [], lockStore: 'file'), 'handle']);
        $this->drainQueue();

        $this->assertSame(2, DoubleNode::$invocations, 'the recovered node ran its nested children exactly once each');

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('paused', $run->status, 'the run reaches its approval gate despite the simulated crash+recovery');
    }

    public function test_full_dry_run_of_the_same_graph_writes_nothing(): void
    {
        $plan = $this->app->make(DryRunPlanner::class)->plan($this->nestedFanoutApprovalGraph(), []);

        $this->assertNotEmpty($plan['plan']->waves);

        $this->app->make(FlowEngine::class)->dryRunGraph($this->nestedFanoutApprovalGraph(), []);

        // This graph contains a flow.approval node, so flow_approvals is a
        // relevant table too (a dry run must never issue a pending approval
        // record); flow_webhook_outbox is included for the same "every
        // persistence table" completeness this graph's node set could touch.
        // flow_definitions is intentionally excluded — setUp() legitimately
        // publishes the 'doubler' child definition there.
        foreach (['flow_runs', 'flow_run_nodes', 'flow_node_cache', 'flow_node_children', 'flow_audit', 'flow_approvals', 'flow_webhook_outbox'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "a dry run writes no {$table} rows");
        }
    }

    public function test_saga_compensates_a_mid_graph_failure_in_correct_order(): void
    {
        // fe (fan-out, nested children) -> comp1 -> comp2 -> failing: on
        // failure, ONLY the completed nodes compensate, in reverse-topological
        // order (comp2 before comp1; the fan-out's children are separate runs,
        // not GraphSaga-compensatable, so fe itself is not in the log).
        $graph = new GraphDefinition(
            [
                new GraphNode('fe', 'flow.foreach', ['flow' => 'doubler', 'maxConcurrency' => 2, 'items' => [1, 2]]),
                new GraphNode('comp1', 'test.saga.comp'),
                new GraphNode('comp2', 'test.saga.comp'),
                new GraphNode('failing', 'test.fail'),
            ],
            [
                new Connection('fe', 'out', 'comp1', 'in'),
                new Connection('comp1', 'out', 'comp2', 'in'),
                new Connection('comp2', 'out', 'failing', 'in'),
            ],
        );

        $runId = $this->app->make(FlowEngine::class)->dispatchGraph($graph, []);
        $this->drainQueue();

        $this->assertSame(['comp2', 'comp1'], CompensatableRecordingNode::$log, 'compensation ran in reverse-topological order');

        // The fan-out spawns its own child runs in flow_runs — query the
        // PARENT run explicitly by id, not first(), which is order-dependent
        // and could pick a succeeded child instead of the compensated parent.
        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('compensated', $run->status);
        $this->assertTrue((bool) $run->compensated);
    }
}
