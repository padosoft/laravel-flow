<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Contracts\ApprovalRepository;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CompensatableRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use RuntimeException;

final class GraphApprovalResumeTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.queue.lock_store', 'file');
        $app['config']->set('laravel-flow.queue.default', 'sync');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('laravel-flow.nodes.handlers', [
            QueueProbeNode::class,
            CompensatableRecordingNode::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
        CompensatableRecordingNode::reset();
    }

    private function runner(): GraphRunner
    {
        return $this->app->make(GraphRunner::class);
    }

    private function engine(): FlowEngine
    {
        return $this->app->make(FlowEngine::class);
    }

    private function gateThenProbeGraph(): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('gate', 'flow.approval'),
                new GraphNode('downstream', 'test.probe'),
            ],
            [new Connection('gate', 'out', 'downstream', 'in')],
        );
    }

    public function test_approval_gate_pauses_graph_run(): void
    {
        $result = $this->runner()->run($this->gateThenProbeGraph(), []);

        $this->assertSame(RunState::Paused, $result->state);
        $this->assertSame(0, QueueProbeNode::count('downstream'), 'downstream never runs while paused');

        $approval = DB::table('flow_approvals')->where('run_id', $result->runId)->first();
        $this->assertNotNull($approval, 'a pending approval row exists');
        $this->assertSame('pending', $approval->status);
        $this->assertSame('gate', $approval->step_name);

        $run = DB::table('flow_runs')->where('id', $result->runId)->first();
        $this->assertSame('paused', $run->status);
        $this->assertNull($run->finished_at, 'a paused run is not finished');

        $gateNode = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'gate')->first();
        $this->assertSame('paused', $gateNode->status);
        $this->assertNull($gateNode->available_at);
    }

    public function test_token_is_hash_only_in_storage(): void
    {
        $result = $this->runner()->run($this->gateThenProbeGraph(), []);

        $this->assertArrayHasKey('gate', $result->approvalTokens, 'the sync run surfaces the issued token');
        $plainToken = $result->approvalTokens['gate']->plainTextToken;

        $approval = DB::table('flow_approvals')->where('run_id', $result->runId)->first();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $approval->token_hash, 'stored value is a sha256 hex digest');
        $this->assertSame(ApprovalTokenManager::hashToken($plainToken), $approval->token_hash);
        $this->assertNotSame($plainToken, $approval->token_hash);

        // The plain token appears nowhere else in the row.
        foreach ((array) $approval as $column => $value) {
            if ($column === 'token_hash' || ! is_string($value)) {
                continue;
            }
            $this->assertStringNotContainsString($plainToken, $value, "column [{$column}] must not leak the plain token");
        }
    }

    public function test_resume_advances_the_graph(): void
    {
        $paused = $this->runner()->run($this->gateThenProbeGraph(), []);
        $token = $paused->approvalTokens['gate']->plainTextToken;

        $resumedRun = $this->engine()->resume($token, ['decision' => 'ship']);

        $this->assertSame('succeeded', $resumedRun->status);
        $this->assertSame(1, QueueProbeNode::count('downstream'), 'downstream ran exactly once after resume');

        $downstreamNode = DB::table('flow_run_nodes')->where('run_id', $paused->runId)->where('node_id', 'downstream')->first();
        $this->assertSame('succeeded', $downstreamNode->status);
        $this->assertSame(['out' => ['id' => 'downstream']], json_decode((string) $downstreamNode->outputs, true));

        $gateNode = DB::table('flow_run_nodes')->where('run_id', $paused->runId)->where('node_id', 'gate')->first();
        $this->assertSame('succeeded', $gateNode->status, 'the gate itself resolves to succeeded on approval');
        $this->assertSame(['decision' => 'ship'], json_decode((string) $gateNode->outputs, true)['out']);
    }

    public function test_reject_fails_and_compensates(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('gate', 'flow.approval'),
            ],
            [new Connection('a', 'out', 'gate', 'in')],
        );

        $paused = $this->runner()->run($graph, []);
        $token = $paused->approvalTokens['gate']->plainTextToken;

        $rejectedRun = $this->engine()->reject($token, ['reason' => 'no']);

        $this->assertSame(['a'], CompensatableRecordingNode::$log, 'the completed upstream node compensated');
        $this->assertSame('compensated', $rejectedRun->status);

        $gateNode = DB::table('flow_run_nodes')->where('run_id', $paused->runId)->where('node_id', 'gate')->first();
        $this->assertSame('failed', $gateNode->status);
    }

    public function test_duplicate_resume_is_idempotent(): void
    {
        $paused = $this->runner()->run($this->gateThenProbeGraph(), []);
        $token = $paused->approvalTokens['gate']->plainTextToken;

        $this->engine()->resume($token, ['decision' => 'ship']);
        $this->assertSame(1, QueueProbeNode::count('downstream'));

        $secondResume = $this->engine()->resume($token, ['decision' => 'ignored']);

        $this->assertSame(1, QueueProbeNode::count('downstream'), 'the duplicate resume did not re-advance the graph');
        $this->assertSame('succeeded', $secondResume->status);
    }

    public function test_reject_on_an_unknown_token_throws(): void
    {
        $this->expectException(FlowExecutionException::class);

        $this->engine()->reject('not-a-real-token');
    }

    public function test_resume_on_a_run_with_no_persisted_graph_gives_a_diagnosable_error(): void
    {
        // Simulates an older run that predates C-PR10's unconditional
        // flow_runs.graph write (or a custom backend that doesn't store/cast
        // the column): the coordinator must name the ACTUAL problem (missing
        // graph data) rather than a misleading "node not found".
        $manager = $this->app->make(ApprovalTokenManager::class);

        DB::table('flow_runs')->insert([
            'id' => 'graphless-run', 'definition_name' => 'graph', 'status' => 'paused',
            'engine' => 'graph', 'graph' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('flow_run_nodes')->insert([
            'run_id' => 'graphless-run', 'node_id' => 'gate', 'node_type' => 'flow.approval',
            'status' => 'paused', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $issued = $manager->issue('graphless-run', 'gate');

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessageMatches('/no persisted graph/i');

        $this->engine()->resume($issued->plainTextToken, ['decision' => 'ship']);
    }

    public function test_resume_recovers_from_a_consumed_token_whose_coordinator_never_ran(): void
    {
        // Crash-window: the token was already consumed as approved (a prior
        // call reached the token-consume step) but the run is STILL `paused`
        // — the coordinator never mutated the node / dispatched CoordinatorJob
        // (process died, transient failure). Simulate that exact state by
        // consuming the token directly, bypassing the engine entirely, then
        // asserting a normal resume() call recovers instead of returning the
        // stuck-paused run forever.
        $paused = $this->runner()->run($this->gateThenProbeGraph(), []);
        $token = $paused->approvalTokens['gate']->plainTextToken;

        $manager = $this->app->make(ApprovalTokenManager::class);
        $manager->approveForRunStatus($token, 'paused', [], ['decision' => 'ship']);

        $stillPaused = DB::table('flow_runs')->where('id', $paused->runId)->first();
        $this->assertSame('paused', $stillPaused->status, 'the simulated crash window: token consumed, coordinator never ran');

        $resumedRun = $this->engine()->resume($token, ['decision' => 'ignored-second-payload']);

        $this->assertSame('succeeded', $resumedRun->status, 'the recovery call re-drove the coordinator');
        $this->assertSame(1, QueueProbeNode::count('downstream'));
    }

    public function test_reject_recovers_from_a_consumed_token_whose_coordinator_never_ran(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('a', 'test.saga.comp'),
                new GraphNode('gate', 'flow.approval'),
            ],
            [new Connection('a', 'out', 'gate', 'in')],
        );

        $paused = $this->runner()->run($graph, []);
        $token = $paused->approvalTokens['gate']->plainTextToken;

        $manager = $this->app->make(ApprovalTokenManager::class);
        $manager->rejectForRunStatus($token, 'paused', [], ['reason' => 'no']);

        $rejectedRun = $this->engine()->reject($token, ['reason' => 'ignored-second-payload']);

        $this->assertSame(['a'], CompensatableRecordingNode::$log, 'the recovery call re-drove the coordinator and compensated');
        $this->assertSame('compensated', $rejectedRun->status);
    }

    public function test_resume_into_a_chained_approval_gate_surfaces_the_downstream_token(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('gate1', 'flow.approval'),
                new GraphNode('gate2', 'flow.approval'),
                new GraphNode('downstream', 'test.probe'),
            ],
            [
                new Connection('gate1', 'out', 'gate2', 'in'),
                new Connection('gate2', 'out', 'downstream', 'in'),
            ],
        );

        $paused = $this->runner()->run($graph, []);
        $firstToken = $paused->approvalTokens['gate1']->plainTextToken;

        $resumedIntoGate2 = $this->engine()->resume($firstToken, ['decision' => 'ship']);

        $this->assertSame('paused', $resumedIntoGate2->status, 'the graph paused again on the second gate');
        $this->assertArrayHasKey('gate2', $resumedIntoGate2->approvalTokens, 'the downstream gate token is surfaced, mirroring v1');
        $this->assertSame(0, QueueProbeNode::count('downstream'), 'downstream of the second gate has not run yet');

        $secondToken = $resumedIntoGate2->approvalTokens['gate2']->plainTextToken;
        $finalRun = $this->engine()->resume($secondToken, ['decision' => 'ship']);

        $this->assertSame('succeeded', $finalRun->status);
        $this->assertSame(1, QueueProbeNode::count('downstream'));
    }

    public function test_resume_into_two_parallel_gates_surfaces_both_downstream_tokens(): void
    {
        // gate0 fans out to TWO independent approval gates, both of which
        // become ready and pause in the SAME coordinator pass. Every paused
        // gate's token must be surfaced, not only the first one found.
        $graph = new GraphDefinition(
            [
                new GraphNode('gate0', 'flow.approval'),
                new GraphNode('gateA', 'flow.approval'),
                new GraphNode('gateB', 'flow.approval'),
            ],
            [
                new Connection('gate0', 'out', 'gateA', 'in'),
                new Connection('gate0', 'out', 'gateB', 'in'),
            ],
        );

        $paused = $this->runner()->run($graph, []);
        $token0 = $paused->approvalTokens['gate0']->plainTextToken;

        $resumed = $this->engine()->resume($token0, ['decision' => 'ship']);

        $this->assertSame('paused', $resumed->status);
        $this->assertArrayHasKey('gateA', $resumed->approvalTokens, 'the first parallel gate token is surfaced');
        $this->assertArrayHasKey('gateB', $resumed->approvalTokens, 'the second parallel gate token is ALSO surfaced');
        $this->assertNotSame(
            $resumed->approvalTokens['gateA']->plainTextToken,
            $resumed->approvalTokens['gateB']->plainTextToken,
        );
    }

    public function test_approval_token_issuance_failure_does_not_abort_the_pause(): void
    {
        // Best-effort: an approval-infrastructure failure must not fail a node
        // that already paused and persisted successfully — mirrors the node
        // cache's own read/write failure discipline in NodeExecutor.
        $this->app->bind(ApprovalRepository::class, fn (): ApprovalRepository => new class implements ApprovalRepository
        {
            public function createPending(string $id, string $runId, string $stepName, string $tokenHash, DateTimeInterface $expiresAt, array $payload = []): FlowApprovalRecord
            {
                throw new RuntimeException('approval backend is down');
            }

            public function findPendingByTokenHash(string $tokenHash): ?FlowApprovalRecord
            {
                return null;
            }

            public function consumePending(string $tokenHash, string $status, array $actor = [], array $payload = [], ?DateTimeInterface $decidedAt = null): ?FlowApprovalRecord
            {
                return null;
            }

            public function expirePending(string $tokenHash, DateTimeInterface $decidedAt): ?FlowApprovalRecord
            {
                return null;
            }

            public function expirePendingForRun(string $runId, DateTimeInterface $decidedAt): int
            {
                return 0;
            }
        });
        $this->app->forgetInstance(ApprovalTokenManager::class);

        Log::spy();

        $result = $this->runner()->run($this->gateThenProbeGraph(), []);

        $this->assertSame(RunState::Paused, $result->state, 'the node still paused despite the token-issuance failure');
        $this->assertArrayNotHasKey('gate', $result->approvalTokens, 'no token was issued');

        $gateNode = DB::table('flow_run_nodes')->where('run_id', $result->runId)->where('node_id', 'gate')->first();
        $this->assertSame('paused', $gateNode->status);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_resume_works_for_a_run_that_started_queued(): void
    {
        // A run started via dispatchGraph() ALREADY pre-seeds every node
        // `pending` (QueueGraphCoordinator::start()), unlike a synchronously-
        // started run. Pin that GraphApprovalCoordinator's own pending-row
        // seeding is a harmless no-op here, not a requirement only the sync
        // path needs.
        $runId = $this->engine()->dispatchGraph($this->gateThenProbeGraph(), []);

        $run = DB::table('flow_runs')->where('id', $runId)->first();
        $this->assertSame('paused', $run->status);

        $manager = $this->app->make(ApprovalTokenManager::class);
        $issued = $manager->reissuePendingForStep($runId, 'gate');
        $this->assertNotNull($issued);

        $resumedRun = $this->engine()->resume($issued->plainTextToken, ['decision' => 'ship']);

        $this->assertSame('succeeded', $resumedRun->status);
        $this->assertSame(1, QueueProbeNode::count('downstream'));
    }
}
