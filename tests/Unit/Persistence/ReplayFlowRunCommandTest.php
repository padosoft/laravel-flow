<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowRun;
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
    ): void {
        $timestamp = Carbon::parse('2026-05-03 09:00:00');

        DB::table('flow_runs')->insert([
            'created_at' => $timestamp,
            'definition_name' => $definitionName,
            'dry_run' => false,
            'finished_at' => $finishedAt === null ? null : Carbon::parse($finishedAt),
            'id' => $runId,
            'input' => json_encode($input),
            'started_at' => $timestamp,
            'status' => $status,
            'updated_at' => $timestamp,
        ]);

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
