<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Support\Facades\Schema;

final class PersistenceMigrationTest extends PersistenceTestCase
{
    public function test_migration_creates_and_drops_flow_tables(): void
    {
        $this->migrateFlowTables();

        $this->assertTrue(Schema::hasTable('flow_runs'));
        $this->assertTrue(Schema::hasTable('flow_steps'));
        $this->assertTrue(Schema::hasTable('flow_audit'));

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

        $this->dropFlowTables();

        $this->assertFalse(Schema::hasTable('flow_audit'));
        $this->assertFalse(Schema::hasTable('flow_steps'));
        $this->assertFalse(Schema::hasTable('flow_runs'));
    }
}
