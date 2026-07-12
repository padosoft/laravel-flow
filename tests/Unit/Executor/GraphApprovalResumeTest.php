<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CompensatableRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

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
