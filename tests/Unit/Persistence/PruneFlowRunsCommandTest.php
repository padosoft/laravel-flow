<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\FlowRun;

final class PruneFlowRunsCommandTest extends PersistenceTestCase
{
    public function test_prune_command_deletes_only_old_terminal_runs_and_related_rows(): void
    {
        $this->migrateFlowTables();
        $now = Carbon::parse('2026-05-03 12:00:00');
        Date::setTestNow($now);

        try {
            $this->insertRunGraph('old-succeeded', FlowRun::STATUS_SUCCEEDED, '2026-03-01 10:00:00');
            $this->insertRunGraph('recent-succeeded', FlowRun::STATUS_SUCCEEDED, '2026-04-20 10:00:00');
            $this->insertRunGraph('running-old', FlowRun::STATUS_RUNNING, null);
            $this->insertRunGraph('cutoff-succeeded', FlowRun::STATUS_SUCCEEDED, '2026-04-03 12:00:00');

            $this->artisan('flow:prune', [
                '--days' => '30',
                '--force' => true,
            ])->assertExitCode(0);
        } finally {
            Date::setTestNow();
        }

        $this->assertRunMissing('old-succeeded');
        $this->assertRelatedRowsMissing('old-succeeded');

        foreach (['recent-succeeded', 'running-old', 'cutoff-succeeded'] as $runId) {
            $this->assertRunExists($runId);
            $this->assertRelatedRowsExist($runId);
        }
    }

    public function test_prune_command_can_dry_run_from_configured_retention(): void
    {
        $this->migrateFlowTables();
        $this->app['config']->set('laravel-flow.persistence.retention.days', '30');
        Date::setTestNow(Carbon::parse('2026-05-03 12:00:00'));

        try {
            $this->insertRunGraph('old-failed', FlowRun::STATUS_FAILED, '2026-03-01 10:00:00');

            $this->artisan('flow:prune', [
                '--dry-run' => true,
            ])
                ->expectsOutputToContain('Matched 1 flow run(s), 1 step row(s), and 1 audit row(s)')
                ->assertExitCode(0);
        } finally {
            Date::setTestNow();
        }

        $this->assertRunExists('old-failed');
        $this->assertRelatedRowsExist('old-failed');
    }

    public function test_prune_command_requires_positive_retention_days(): void
    {
        $this->migrateFlowTables();

        $this->artisan('flow:prune')
            ->expectsOutputToContain('Set --days to a positive integer')
            ->assertExitCode(1);

        $this->artisan('flow:prune', [
            '--days' => '0',
        ])
            ->expectsOutputToContain('Set --days to a positive integer')
            ->assertExitCode(1);
    }

    public function test_prune_command_fails_cleanly_when_persistence_tables_are_missing(): void
    {
        $this->artisan('flow:prune', [
            '--days' => '30',
        ])
            ->expectsOutputToContain('Laravel Flow persistence tables were not found')
            ->assertExitCode(1);
    }

    private function insertRunGraph(string $runId, string $status, ?string $finishedAt): void
    {
        $timestamp = Carbon::parse('2026-03-01 09:00:00');

        DB::table('flow_runs')->insert([
            'created_at' => $timestamp,
            'definition_name' => 'flow.prune',
            'dry_run' => false,
            'finished_at' => $finishedAt === null ? null : Carbon::parse($finishedAt),
            'id' => $runId,
            'input' => json_encode(['safe' => true]),
            'started_at' => $timestamp,
            'status' => $status,
            'updated_at' => $timestamp,
        ]);

        DB::table('flow_steps')->insert([
            'created_at' => $timestamp,
            'dry_run_skipped' => false,
            'handler' => 'Tests\\Handler',
            'run_id' => $runId,
            'sequence' => 1,
            'started_at' => $timestamp,
            'status' => $status === FlowRun::STATUS_RUNNING ? 'running' : 'succeeded',
            'step_name' => 'step-one',
            'updated_at' => $timestamp,
        ]);

        DB::table('flow_audit')->insert([
            'created_at' => $timestamp,
            'event' => 'FlowStepCompleted',
            'occurred_at' => $timestamp,
            'payload' => json_encode(['status' => 'succeeded']),
            'run_id' => $runId,
            'step_name' => 'step-one',
        ]);
    }

    private function assertRunExists(string $runId): void
    {
        $this->assertSame(1, (int) DB::table('flow_runs')->where('id', $runId)->count());
    }

    private function assertRunMissing(string $runId): void
    {
        $this->assertSame(0, (int) DB::table('flow_runs')->where('id', $runId)->count());
    }

    private function assertRelatedRowsExist(string $runId): void
    {
        $this->assertSame(1, (int) DB::table('flow_steps')->where('run_id', $runId)->count());
        $this->assertSame(1, (int) DB::table('flow_audit')->where('run_id', $runId)->count());
    }

    private function assertRelatedRowsMissing(string $runId): void
    {
        $this->assertSame(0, (int) DB::table('flow_steps')->where('run_id', $runId)->count());
        $this->assertSame(0, (int) DB::table('flow_audit')->where('run_id', $runId)->count());
    }
}
