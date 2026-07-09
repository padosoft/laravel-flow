<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\JsonEmitNode;

final class ReplayGraphRunCommandTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [JsonEmitNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
    }

    public function test_pinned_graph_run_replays_stored_version(): void
    {
        /** @var DefinitionRepository $definitions */
        $definitions = $this->app->make(DefinitionRepository::class);

        // v1: a single node. v2: two nodes (a superset) — the pinned replay must
        // re-run v1's node set, not the current latest (v2).
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

        $this->artisan('flow:replay', ['runId' => 'graph-run-1'])
            ->expectsOutputToContain('using pinned version [1]')
            ->assertExitCode(0);

        $replayed = DB::table('flow_runs')
            ->where('replayed_from_run_id', 'graph-run-1')
            ->where('engine', 'graph')
            ->first();

        $this->assertNotNull($replayed);

        $nodeIds = DB::table('flow_run_nodes')
            ->where('run_id', $replayed->id)
            ->pluck('node_id')
            ->all();

        // Replayed v1's node set exactly (only 'a'), not v2's ('a' + 'b').
        $this->assertSame(['a'], $nodeIds);
    }

    public function test_unpinned_run_is_not_treated_as_a_graph_replay(): void
    {
        // No engine/version pin: the command falls through to the v1 path, which
        // fails because the definition is not registered as a v1 flow.
        DB::table('flow_runs')->insert([
            'id' => 'legacy-run-1',
            'definition_name' => 'flow.graph',
            'dry_run' => false,
            'input' => json_encode([]),
            'status' => 'succeeded',
            'finished_at' => Carbon::parse('2026-05-03 10:00:00'),
        ]);

        $this->artisan('flow:replay', ['runId' => 'legacy-run-1'])
            ->expectsOutputToContain('is not registered in the current application')
            ->assertExitCode(1);
    }
}
