<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\JsonEmitNode;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

/**
 * Covers the programmatic {@see FlowEngine::replay()} @api seam: the legacy and
 * pinned-graph happy paths (each returning a new linked {@see FlowRun}) plus its
 * error contract (non-terminal, unknown run, non-array input, unpinned graph
 * run, persistence disabled). The `flow:replay` command's own path is exercised
 * separately by {@see ReplayFlowRunCommandTest}.
 */
final class ReplayRunTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Register the graph node used by the pinned-graph replay test.
        $app['config']->set('laravel-flow.nodes.handlers', [JsonEmitNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_replay_reexecutes_a_pinned_graph_run_at_its_stored_version(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        /** @var DefinitionRepository $definitions */
        $definitions = $this->app->make(DefinitionRepository::class);

        // v1 = one node; v2 = two nodes. A pinned replay must re-run v1's set.
        $definitions->createDraft('flow.graph', new GraphDefinition([new GraphNode('a', 'test.jsonemit')], []));
        $v1 = $definitions->publish('flow.graph', 1);
        $definitions->createDraft('flow.graph', new GraphDefinition([
            new GraphNode('a', 'test.jsonemit'),
            new GraphNode('b', 'test.jsonemit'),
        ], []));
        $definitions->publish('flow.graph', 2);

        DB::table('flow_runs')->insert([
            'id' => 'graph-run-1',
            'definition_name' => 'flow.graph',
            'engine' => 'graph',
            'definition_version' => 1,
            'definition_checksum' => $v1->checksum,
            'dry_run' => false,
            'input' => json_encode(['tenant' => 'acme']),
            'status' => 'succeeded',
            'finished_at' => Carbon::parse('2026-05-03 10:00:00'),
        ]);

        // The programmatic seam returns a FlowRun (converted from the graph
        // engine's GraphRunResult), linked to the source run.
        $replayed = $engine->replay('graph-run-1');

        $this->assertNotSame('graph-run-1', $replayed->id);
        $this->assertSame('graph-run-1', $replayed->replayedFromRunId);

        // Re-ran the PINNED v1 node set exactly (only 'a'), never latest v2.
        $nodeIds = FlowRunNodeRecord::query()->where('run_id', $replayed->id)->pluck('node_id')->all();
        $this->assertSame(['a'], $nodeIds);
    }

    public function test_replay_of_a_pinned_graph_that_repauses_carries_the_fresh_approval_token(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        /** @var DefinitionRepository $definitions */
        $definitions = $this->app->make(DefinitionRepository::class);
        // A graph whose single node is a built-in approval gate — running it
        // pauses and issues a one-time token.
        $definitions->createDraft('flow.gated', new GraphDefinition([new GraphNode('gate', 'flow.approval')], []));
        $v1 = $definitions->publish('flow.gated', 1);

        // A terminal, pinned run of that graph to replay.
        DB::table('flow_runs')->insert([
            'id' => 'gated-run',
            'definition_name' => 'flow.gated',
            'engine' => 'graph',
            'definition_version' => 1,
            'definition_checksum' => $v1->checksum,
            'dry_run' => false,
            'input' => json_encode([]),
            'status' => 'succeeded',
            'finished_at' => Carbon::parse('2026-05-03 10:00:00'),
        ]);

        // Replaying re-runs the graph, which pauses at the gate. The returned
        // FlowRun MUST carry the fresh plain token (it lives only in the
        // GraphRunResult) so the newly paused run is actually resumable.
        $replayed = $engine->replay('gated-run');

        $this->assertSame('gated-run', $replayed->replayedFromRunId);
        $this->assertNotEmpty($replayed->approvalTokens);
        $this->assertArrayHasKey('gate', $replayed->approvalTokens);
    }

    public function test_replay_reexecutes_a_terminal_run_as_a_new_linked_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.replay.ok')
            ->step('s', AlwaysSucceedsHandler::class)
            ->register();

        $original = $engine->execute('flow.replay.ok', ['k' => 'v']);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $original->status);

        $replayed = $engine->replay($original->id);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $replayed->status);
        $this->assertNotSame($original->id, $replayed->id);
        // The new run is linked back to the source run.
        $this->assertSame($original->id, $replayed->replayedFromRunId);
    }

    public function test_replay_throws_for_a_non_terminal_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.replay.paused')
            ->step('s', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $paused = $engine->execute('flow.replay.paused', []);
        $this->assertSame(FlowRun::STATUS_PAUSED, $paused->status);

        $this->expectException(FlowExecutionException::class);
        $engine->replay($paused->id);
    }

    public function test_replay_throws_when_the_stored_input_is_not_an_array(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.replay.badinput')
            ->step('s', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.replay.badinput', []);

        // Corrupt the persisted input to a scalar JSON value.
        DB::table('flow_runs')->where('id', $run->id)->update(['input' => json_encode('not-an-array')]);

        $this->expectException(FlowExecutionException::class);
        $engine->replay($run->id);
    }

    public function test_replay_throws_for_an_unpinned_graph_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        // A graph run with no pinned version/checksum must NOT fall through to
        // the legacy path — replay rejects it explicitly.
        DB::table('flow_runs')->insert([
            'id' => 'graph-unpinned',
            'definition_name' => 'flow.graph',
            'engine' => 'graph',
            'definition_version' => null,
            'definition_checksum' => null,
            'dry_run' => false,
            'input' => json_encode([]),
            'status' => 'succeeded',
            'finished_at' => Carbon::parse('2026-05-03 10:00:00'),
        ]);

        $this->expectException(FlowExecutionException::class);
        $engine->replay('graph-unpinned');
    }

    public function test_replay_throws_for_an_unknown_run(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $this->expectException(FlowExecutionException::class);
        $engine->replay('does-not-exist');
    }

    public function test_replay_requires_persistence_enabled(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $this->app->forgetInstance(FlowEngine::class);
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);
        $engine->replay('any-run');
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        return $engine;
    }
}
