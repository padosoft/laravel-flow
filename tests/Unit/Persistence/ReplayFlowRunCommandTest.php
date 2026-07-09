<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;

final class ReplayFlowRunCommandTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_replay_command_creates_new_linked_run_without_mutating_original(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->insertRunGraph('original-failed', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ]);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->artisan('flow:replay', ['runId' => 'original-failed'])
            ->expectsOutputToContain('Replayed flow run [original-failed] as')
            ->assertExitCode(0);

        $original = FlowRunRecord::query()->find('original-failed');
        $this->assertInstanceOf(FlowRunRecord::class, $original);
        $this->assertSame(FlowRun::STATUS_FAILED, $original->status);

        $replay = FlowRunRecord::query()
            ->where('replayed_from_run_id', 'original-failed')
            ->first();
        $this->assertInstanceOf(FlowRunRecord::class, $replay);
        $this->assertNotSame('original-failed', $replay->id);
        $this->assertSame('flow.replay', $replay->definition_name);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $replay->status);
        $this->assertSame(['tenant' => 'acme'], $replay->input);
        $this->assertNull($replay->idempotency_key);
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }

    public function test_replay_command_warns_about_definition_drift_but_uses_current_definition(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->insertRunGraph('drifted-failed', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ], handler: 'Old\\Handler');

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->artisan('flow:replay', ['runId' => 'drifted-failed'])
            ->expectsOutputToContain('Definition drift detected for [flow.replay]')
            ->expectsOutputToContain('Replayed flow run [drifted-failed] as')
            ->assertExitCode(0);
    }

    public function test_replay_command_does_not_warn_when_executed_steps_match_current_definition_prefix(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->insertRunGraph('prefix-failed', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ]);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', SecondHandler::class)
            ->register();

        $this->artisan('flow:replay', ['runId' => 'prefix-failed'])
            ->doesntExpectOutputToContain('Definition drift detected')
            ->expectsOutputToContain('Replayed flow run [prefix-failed] as')
            ->assertExitCode(0);
    }

    public function test_replay_command_does_not_warn_for_a_pinned_unchanged_flow(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $definition = $this->app->make(FlowEngine::class)->definition('flow.replay');
        $checksum = (new GraphSerializer)->checksum($definition->toGraphDefinition());

        $this->insertRunGraph('pinned-unchanged', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ], definitionVersion: 1, definitionChecksum: $checksum);

        $this->artisan('flow:replay', ['runId' => 'pinned-unchanged'])
            ->doesntExpectOutputToContain('Definition drift detected')
            ->doesntExpectOutputToContain('was pinned to')
            ->expectsOutputToContain('Replayed flow run [pinned-unchanged] as')
            ->assertExitCode(0);
    }

    public function test_replay_command_warns_with_version_context_for_a_pinned_drifted_flow(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->insertRunGraph('pinned-drifted', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ], definitionVersion: 3, definitionChecksum: str_repeat('a', 64));

        $this->artisan('flow:replay', ['runId' => 'pinned-drifted'])
            ->doesntExpectOutputToContain('Definition drift detected for')
            ->expectsOutputToContain('Flow run [pinned-drifted] was pinned to [flow.replay] version [3]')
            ->expectsOutputToContain('Replayed flow run [pinned-drifted] as')
            ->assertExitCode(0);
    }

    /**
     * Copilot review (round 3, PR #53): GraphSerializer::checksum() is
     * documented to throw JsonException (json_encode(..., JSON_THROW_ON_ERROR)
     * on malformed UTF-8 or non-encodable values); warnAboutPinnedDrift()
     * called it unguarded, so a definition whose graph can't be
     * JSON-encoded would crash the whole replay instead of degrading
     * gracefully. A step handler class name containing invalid UTF-8
     * bytes flows straight into the compiled graph's node config and
     * reproduces the real throw path (verified: json_encode() with
     * JSON_THROW_ON_ERROR throws on "\xB1\x31") without needing to fake
     * GraphSerializer.
     */
    public function test_replay_command_degrades_gracefully_when_checksum_computation_throws(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', "AlwaysSucceedsHandler\xB1\x31")
            ->register();

        $this->insertRunGraph('pinned-unencodable', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ], definitionVersion: 1, definitionChecksum: str_repeat('a', 64));

        $this->artisan('flow:replay', ['runId' => 'pinned-unencodable'])
            ->expectsOutputToContain('Could not evaluate definition drift for flow run [pinned-unencodable]; replay continues without a drift check.')
            ->doesntExpectOutputToContain('was pinned to')
            ->assertExitCode(1);
    }

    /**
     * Copilot review (Macro B PR #54): warnAboutPinnedDrift() only caught
     * JsonException, but FlowDefinition::toGraphDefinition() can also
     * throw InvalidGraphException (its structural "at least one node"
     * invariant, when a definition compiles to zero graph nodes). The v1
     * builder's register() rejects zero steps, so the only way to
     * reproduce this is a FlowDefinition built directly (bypassing the
     * builder) with an empty steps list, then registered in-memory.
     */
    public function test_replay_command_degrades_gracefully_when_graph_compilation_is_structurally_invalid(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        $this->app->make(FlowEngine::class)->registerDefinition(
            new FlowDefinition('flow.replay-invalid-graph', [], []),
        );

        $this->insertRunGraph('pinned-invalid-graph', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ], definitionName: 'flow.replay-invalid-graph', definitionVersion: 1, definitionChecksum: str_repeat('a', 64));

        $this->artisan('flow:replay', ['runId' => 'pinned-invalid-graph'])
            ->expectsOutputToContain('Could not evaluate definition drift for flow run [pinned-invalid-graph]; replay continues without a drift check.')
            ->doesntExpectOutputToContain('was pinned to')
            ->assertExitCode(0);
    }

    public function test_replay_command_rejects_non_terminal_original_runs(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->insertRunGraph('running-run', FlowRun::STATUS_RUNNING, [
            'tenant' => 'acme',
        ], finishedAt: null);

        $this->artisan('flow:replay', ['runId' => 'running-run'])
            ->expectsOutputToContain('Flow run [running-run] is not terminal')
            ->assertExitCode(1);
    }

    public function test_replay_command_requires_persistence_enabled(): void
    {
        $this->artisan('flow:replay', ['runId' => 'missing'])
            ->expectsOutputToContain('Enable laravel-flow.persistence.enabled')
            ->assertExitCode(1);
    }

    public function test_replay_command_fails_cleanly_when_original_run_is_missing(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        $this->artisan('flow:replay', ['runId' => 'missing'])
            ->expectsOutputToContain('Flow run [missing] was not found.')
            ->assertExitCode(1);
    }

    public function test_replay_command_fails_cleanly_when_persistence_tables_are_missing(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        $this->artisan('flow:replay', ['runId' => 'missing'])
            ->expectsOutputToContain('Laravel Flow persistence tables were not found')
            ->assertExitCode(1);
    }

    public function test_replay_command_fails_cleanly_when_step_table_is_missing(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);

        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('definition_name');
            $table->string('status', 32);
            $table->boolean('dry_run')->default(false);
            $table->json('input')->nullable();
            $table->timestampsTz();
        });

        DB::table('flow_runs')->insert([
            'definition_name' => 'flow.replay',
            'dry_run' => false,
            'id' => 'partial-run',
            'input' => json_encode(['tenant' => 'acme']),
            'status' => FlowRun::STATUS_FAILED,
        ]);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->artisan('flow:replay', ['runId' => 'partial-run'])
            ->expectsOutputToContain('Laravel Flow persistence tables were not found or could not be queried')
            ->assertExitCode(1);
    }

    public function test_replay_command_fails_cleanly_when_replay_lineage_column_is_missing(): void
    {
        $legacyMigration = require __DIR__.'/../../../database/migrations/2026_05_02_000001_create_laravel_flow_tables.php';
        $legacyMigration->up();
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->insertRunGraph('legacy-failed', FlowRun::STATUS_FAILED, [
            'tenant' => 'acme',
        ]);

        Flow::define('flow.replay')
            ->withInput(['tenant'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->artisan('flow:replay', ['runId' => 'legacy-failed'])
            ->expectsOutputToContain('Laravel Flow replay could not persist or query its tables')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function insertRunGraph(
        string $runId,
        string $status,
        array $input,
        string $definitionName = 'flow.replay',
        string $handler = AlwaysSucceedsHandler::class,
        ?string $finishedAt = '2026-05-03 10:00:00',
        ?int $definitionVersion = null,
        ?string $definitionChecksum = null,
    ): void {
        $timestamp = Carbon::parse('2026-05-03 09:00:00');

        $attributes = [
            'created_at' => $timestamp,
            'definition_name' => $definitionName,
            'dry_run' => false,
            'finished_at' => $finishedAt === null ? null : Carbon::parse($finishedAt),
            'id' => $runId,
            'input' => json_encode($input),
            'started_at' => $timestamp,
            'status' => $status,
            'updated_at' => $timestamp,
        ];

        // Omit the version-pinning keys from the insert array entirely for
        // callers that pass no pin (the stored column value is NULL either
        // way once the schema has these nullable columns — only the INSERT
        // statement differs, not what ends up persisted), matching how
        // production code builds this array conditionally rather than
        // always setting both keys.
        if ($definitionVersion !== null || $definitionChecksum !== null) {
            $attributes['definition_version'] = $definitionVersion;
            $attributes['definition_checksum'] = $definitionChecksum;
        }

        DB::table('flow_runs')->insert($attributes);

        DB::table('flow_steps')->insert([
            'created_at' => $timestamp,
            'dry_run_skipped' => false,
            'handler' => $handler,
            'run_id' => $runId,
            'sequence' => 1,
            'started_at' => $timestamp,
            'status' => $status === FlowRun::STATUS_RUNNING ? 'running' : 'failed',
            'step_name' => 'one',
            'updated_at' => $timestamp,
        ]);
    }
}
