<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

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
        $this->assertTrue(Schema::hasTable('flow_audit'));
        $this->assertTrue(Schema::hasTable('flow_approvals'));
        $this->assertTrue(Schema::hasTable('flow_webhook_outbox'));

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

        $this->dropFlowTables();

        $this->assertFalse(Schema::hasTable('flow_webhook_outbox'));
        $this->assertFalse(Schema::hasTable('flow_approvals'));
        $this->assertFalse(Schema::hasTable('flow_audit'));
        $this->assertFalse(Schema::hasTable('flow_steps'));
        $this->assertFalse(Schema::hasTable('flow_runs'));
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
