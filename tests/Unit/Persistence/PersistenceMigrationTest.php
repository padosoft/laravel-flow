<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PersistenceMigrationTest extends PersistenceTestCase
{
    public function test_migration_creates_and_drops_flow_tables(): void
    {
        $this->migrateFlowTables();

        $this->assertTrue(Schema::hasTable('flow_runs'));
        $this->assertTrue(Schema::hasTable('flow_steps'));
        $this->assertTrue(Schema::hasTable('flow_run_nodes'));
        $this->assertTrue(Schema::hasTable('flow_audit'));
        $this->assertTrue(Schema::hasTable('flow_approvals'));
        $this->assertTrue(Schema::hasTable('flow_webhook_outbox'));
        $this->assertTrue(Schema::hasTable('flow_definitions'));

        $this->assertTrue(Schema::hasColumns('flow_runs', [
            'id',
            'definition_name',
            'status',
            'input',
            'output',
            'business_impact',
            'failed_step',
            'compensated',
            'compensation_status',
            'correlation_id',
            'idempotency_key',
            'replayed_from_run_id',
            'definition_version',
            'definition_checksum',
            'engine',
            'nodes_total',
            'nodes_completed',
            'nodes_failed',
        ]));
        $this->assertTrue(Schema::hasColumns('flow_run_nodes', [
            'id',
            'run_id',
            'sequence',
            'node_id',
            'node_type',
            'handler',
            'status',
            'attempts',
            'inputs',
            'outputs',
            'business_impact',
            'error_class',
            'error_message',
            'dry_run_skipped',
            'cache_hit',
            'available_at',
            'started_at',
            'finished_at',
            'duration_ms',
        ]));
        $this->assertTrue(Schema::hasColumns('flow_steps', [
            'run_id',
            'sequence',
            'step_name',
            'status',
            'output',
            'business_impact',
            'error_class',
            'error_message',
            'dry_run_skipped',
        ]));
        $this->assertTrue(Schema::hasColumns('flow_audit', [
            'run_id',
            'step_name',
            'event',
            'payload',
            'business_impact',
            'occurred_at',
        ]));
        $this->assertTrue(Schema::hasColumns('flow_approvals', [
            'id',
            'run_id',
            'step_name',
            'status',
            'token_hash',
            'previous_token_hash',
            'payload',
            'actor',
            'expires_at',
            'consumed_at',
            'decided_at',
        ]));
        $this->assertFalse(Schema::hasColumn('flow_approvals', 'token'));
        $this->assertTrue(Schema::hasColumns('flow_webhook_outbox', [
            'run_id',
            'approval_id',
            'event',
            'status',
            'payload',
            'attempts',
            'max_attempts',
            'available_at',
            'delivered_at',
            'failed_at',
            'last_error',
        ]));
        $this->assertTrue(Schema::hasColumns('flow_definitions', [
            'id',
            'name',
            'version',
            'status',
            'graph',
            'checksum',
            'signature',
            'published_at',
        ]));

        $this->dropFlowTables();

        $this->assertFalse(Schema::hasTable('flow_definitions'));
        $this->assertFalse(Schema::hasTable('flow_webhook_outbox'));
        $this->assertFalse(Schema::hasTable('flow_approvals'));
        $this->assertFalse(Schema::hasTable('flow_audit'));
        $this->assertFalse(Schema::hasTable('flow_run_nodes'));
        $this->assertFalse(Schema::hasTable('flow_steps'));
        $this->assertFalse(Schema::hasTable('flow_runs'));
    }

    public function test_run_nodes_rows_cascade_when_run_is_deleted(): void
    {
        $this->migrateFlowTables();

        DB::table('flow_runs')->insert([
            'id' => '00000000-0000-4000-8000-000000000200',
            'definition_name' => 'flow.node.cascade',
            'dry_run' => false,
            'status' => 'succeeded',
        ]);
        DB::table('flow_run_nodes')->insert([
            'run_id' => '00000000-0000-4000-8000-000000000200',
            'node_id' => 'step-one',
            'node_type' => 'legacy.step',
            'status' => 'succeeded',
        ]);

        DB::table('flow_runs')
            ->where('id', '00000000-0000-4000-8000-000000000200')
            ->delete();

        $this->assertSame(0, DB::table('flow_run_nodes')->where('run_id', '00000000-0000-4000-8000-000000000200')->count());
    }

    public function test_run_nodes_enforces_unique_run_and_node(): void
    {
        $this->migrateFlowTables();

        DB::table('flow_runs')->insert([
            'id' => '00000000-0000-4000-8000-000000000201',
            'definition_name' => 'flow.node.unique',
            'dry_run' => false,
            'status' => 'running',
        ]);
        DB::table('flow_run_nodes')->insert([
            'run_id' => '00000000-0000-4000-8000-000000000201',
            'node_id' => 'step-one',
            'node_type' => 'legacy.step',
            'status' => 'running',
        ]);

        $this->expectException(QueryException::class);

        DB::table('flow_run_nodes')->insert([
            'run_id' => '00000000-0000-4000-8000-000000000201',
            'node_id' => 'step-one',
            'node_type' => 'legacy.step',
            'status' => 'succeeded',
        ]);
    }

    public function test_graph_columns_migration_adds_columns_to_existing_flow_runs_table(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('definition_checksum', 64)->nullable();
        });

        $migration = require __DIR__.'/../../../database/migrations/2026_07_09_000008_add_graph_columns_to_laravel_flow_runs.php';
        $migration->up();

        $this->assertTrue(Schema::hasColumns('flow_runs', ['engine', 'nodes_total', 'nodes_completed', 'nodes_failed']));

        // Re-running up() on the now-complete table stays a no-op.
        $migration->up();
        $this->assertTrue(Schema::hasColumns('flow_runs', ['engine', 'nodes_total', 'nodes_completed', 'nodes_failed']));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('flow_runs', 'engine'));
        $this->assertFalse(Schema::hasColumn('flow_runs', 'nodes_total'));
        $this->assertFalse(Schema::hasColumn('flow_runs', 'nodes_completed'));
        $this->assertFalse(Schema::hasColumn('flow_runs', 'nodes_failed'));
    }

    public function test_flow_definitions_enforces_unique_name_version(): void
    {
        $this->migrateFlowTables();

        DB::table('flow_definitions')->insert([
            'checksum' => str_repeat('a', 64),
            'graph' => json_encode(['schema_version' => 1]),
            'name' => 'onboarding',
            'status' => 'draft',
            'version' => 1,
        ]);

        $this->expectException(QueryException::class);

        DB::table('flow_definitions')->insert([
            'checksum' => str_repeat('b', 64),
            'graph' => json_encode(['schema_version' => 1]),
            'name' => 'onboarding',
            'status' => 'draft',
            'version' => 1,
        ]);
    }

    public function test_audit_rows_cascade_when_run_is_deleted(): void
    {
        $this->migrateFlowTables();

        DB::table('flow_runs')->insert([
            'id' => '00000000-0000-4000-8000-000000000099',
            'definition_name' => 'flow.audit.cascade',
            'dry_run' => false,
            'status' => 'succeeded',
        ]);
        DB::table('flow_audit')->insert([
            'run_id' => '00000000-0000-4000-8000-000000000099',
            'event' => 'FlowStepStarted',
        ]);

        DB::table('flow_runs')
            ->where('id', '00000000-0000-4000-8000-000000000099')
            ->delete();

        $this->assertSame(0, DB::table('flow_audit')->where('run_id', '00000000-0000-4000-8000-000000000099')->count());
    }

    public function test_replay_lineage_migration_adds_column_to_existing_flow_runs_table(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('idempotency_key')->nullable();
        });

        $migration = require __DIR__.'/../../../database/migrations/2026_05_04_000002_add_replay_lineage_to_laravel_flow_runs.php';
        $migration->up();

        $this->assertTrue(Schema::hasColumn('flow_runs', 'replayed_from_run_id'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('flow_runs', 'replayed_from_run_id'));
    }

    public function test_definition_version_migration_adds_columns_to_existing_flow_runs_table(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
        });

        $migration = require __DIR__.'/../../../database/migrations/2026_07_08_000006_add_definition_version_to_laravel_flow_runs.php';
        $migration->up();

        $this->assertTrue(Schema::hasColumns('flow_runs', ['definition_version', 'definition_checksum']));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('flow_runs', 'definition_version'));
        $this->assertFalse(Schema::hasColumn('flow_runs', 'definition_checksum'));
    }

    public function test_definition_version_migration_adds_only_the_missing_column_on_partial_state(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->unsignedInteger('definition_version')->nullable();
        });

        $migration = require __DIR__.'/../../../database/migrations/2026_07_08_000006_add_definition_version_to_laravel_flow_runs.php';
        $migration->up();

        $this->assertTrue(Schema::hasColumns('flow_runs', ['definition_version', 'definition_checksum']));

        // Re-running up() on the now-complete table must stay a no-op,
        // not attempt to re-add either column.
        $migration->up();
        $this->assertTrue(Schema::hasColumns('flow_runs', ['definition_version', 'definition_checksum']));
    }

    public function test_definition_version_migration_down_drops_only_the_columns_that_exist(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('definition_checksum', 64)->nullable();
        });

        $migration = require __DIR__.'/../../../database/migrations/2026_07_08_000006_add_definition_version_to_laravel_flow_runs.php';

        $migration->down();

        $this->assertFalse(Schema::hasColumn('flow_runs', 'definition_version'));
        $this->assertFalse(Schema::hasColumn('flow_runs', 'definition_checksum'));
    }

    public function test_approval_and_webhook_tables_cascade_when_run_is_deleted(): void
    {
        $this->migrateFlowTables();

        DB::table('flow_runs')->insert([
            'id' => '00000000-0000-4000-8000-000000000123',
            'definition_name' => 'flow.approval.cascade',
            'dry_run' => false,
            'status' => 'paused',
        ]);
        DB::table('flow_approvals')->insert([
            'id' => '00000000-0000-4000-8000-000000000124',
            'run_id' => '00000000-0000-4000-8000-000000000123',
            'status' => 'pending',
            'step_name' => 'manager-approval',
            'token_hash' => str_repeat('a', 64),
        ]);
        DB::table('flow_webhook_outbox')->insert([
            'approval_id' => '00000000-0000-4000-8000-000000000124',
            'event' => 'flow.paused',
            'run_id' => '00000000-0000-4000-8000-000000000123',
            'status' => 'pending',
        ]);

        DB::table('flow_runs')
            ->where('id', '00000000-0000-4000-8000-000000000123')
            ->delete();

        $this->assertSame(0, DB::table('flow_approvals')->where('run_id', '00000000-0000-4000-8000-000000000123')->count());
        $this->assertSame(0, DB::table('flow_webhook_outbox')->where('run_id', '00000000-0000-4000-8000-000000000123')->count());
    }
}
