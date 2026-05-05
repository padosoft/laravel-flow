<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Dashboard;

use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Dashboard\Pagination;
use Padosoft\LaravelFlow\Dashboard\RunFilter;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

final class FlowDashboardReadModelTest extends PersistenceTestCase
{
    public function test_list_runs_returns_paginated_results_in_descending_started_at_order(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.list-runs')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        Date::setTestNow('2026-05-05 10:00:00');
        $first = $engine->execute('flow.dashboard.list-runs', ['marker' => 1]);

        Date::setTestNow('2026-05-05 11:00:00');
        $second = $engine->execute('flow.dashboard.list-runs', ['marker' => 2]);

        Date::setTestNow();

        $reader = $this->reader();
        $page = $reader->listRuns(new RunFilter, new Pagination(1, 10));

        $this->assertSame(2, $page->total);
        $this->assertCount(2, $page->items);
        $this->assertSame(1, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertSame(1, $page->totalPages());

        $ids = array_map(static fn ($item) => $item->id, $page->items);
        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_list_runs_filters_by_status_definition_correlation_and_compensated(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.filter-success')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();
        $engine->define('flow.dashboard.filter-fail')
            ->step('one', AlwaysFailsHandler::class)
            ->register();

        $engine->execute('flow.dashboard.filter-success', []);
        $engine->execute('flow.dashboard.filter-fail', []);

        $reader = $this->reader();

        $byStatus = $reader->listRuns(new RunFilter(status: FlowRun::STATUS_FAILED), new Pagination(1, 10));
        $this->assertSame(1, $byStatus->total);
        $this->assertSame('flow.dashboard.filter-fail', $byStatus->items[0]->definitionName);

        $byDefinition = $reader->listRuns(new RunFilter(definitionName: 'flow.dashboard.filter-success'), new Pagination(1, 10));
        $this->assertSame(1, $byDefinition->total);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $byDefinition->items[0]->status);

        $byCompensated = $reader->listRuns(new RunFilter(compensated: false), new Pagination(1, 10));
        $this->assertSame(2, $byCompensated->total);
    }

    public function test_find_run_returns_detail_with_steps_audit_and_redacted_payloads(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.detail')
            ->step('create', AlwaysSucceedsHandler::class)
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.dashboard.detail', ['payload' => 'value']);
        $detail = $this->reader()->findRun($run->id);

        $this->assertNotNull($detail);
        $this->assertSame($run->id, $detail->run->id);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $detail->run->status);
        $this->assertCount(2, $detail->steps);
        $this->assertSame('create', $detail->steps[0]->name);
        $this->assertSame('publish', $detail->steps[1]->name);
        $this->assertSame(['payload' => 'value'], $detail->input);
        $this->assertNotEmpty($detail->audit);
    }

    public function test_find_run_returns_null_for_unknown_id(): void
    {
        $this->migrateFlowTables();

        $this->assertNull($this->reader()->findRun('does-not-exist'));
    }

    public function test_pending_approvals_returns_only_pending_records(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.pending-approval')
            ->step('one', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $paused = $engine->execute('flow.dashboard.pending-approval', []);

        $pending = $this->reader()->pendingApprovals();
        $this->assertCount(1, $pending);
        $this->assertSame($paused->id, $pending[0]->runId);
        $this->assertSame('manager', $pending[0]->stepName);
        $this->assertSame(FlowApprovalRecord::STATUS_PENDING, $pending[0]->status);

        $approved = $engine->resume($paused->approvalTokens['manager']->plainTextToken);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $approved->status);

        $this->assertSame([], $this->reader()->pendingApprovals());
    }

    public function test_kpis_returns_counts_per_status_and_outbox_state(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.kpi-success')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();
        $engine->define('flow.dashboard.kpi-fail')
            ->step('one', AlwaysFailsHandler::class)
            ->register();

        $engine->execute('flow.dashboard.kpi-success', []);
        $engine->execute('flow.dashboard.kpi-success', []);
        $engine->execute('flow.dashboard.kpi-fail', []);

        $kpis = $this->reader()->kpis();
        $this->assertSame(3, $kpis->totalRuns);
        $this->assertSame(0, $kpis->runningRuns);
        $this->assertSame(0, $kpis->pausedRuns);
        $this->assertSame(1, $kpis->failedRuns);
        $this->assertSame(0, $kpis->compensatedRuns);
        $this->assertSame(0, $kpis->pendingApprovals);
        $this->assertSame(3, $kpis->webhookOutboxPending);
        $this->assertSame(0, $kpis->webhookOutboxFailed);
    }

    public function test_failed_webhook_outbox_returns_only_failed_rows(): void
    {
        $this->migrateFlowTables();

        FlowWebhookOutboxRecord::query()->insert([
            'event' => 'flow.completed',
            'status' => FlowWebhookOutboxRecord::STATUS_FAILED,
            'attempts' => 3,
            'max_attempts' => 3,
            'last_error' => 'connection refused',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        FlowWebhookOutboxRecord::query()->insert([
            'event' => 'flow.completed',
            'status' => FlowWebhookOutboxRecord::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $failed = $this->reader()->failedWebhookOutbox();
        $this->assertCount(1, $failed);
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_FAILED, $failed[0]->status);
        $this->assertSame('connection refused', $failed[0]->lastError);
    }

    private function reader(): FlowDashboardReadModel
    {
        return $this->app->make(FlowDashboardReadModel::class);
    }

    private function engineWithPersistence(): FlowEngine
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', true);
        $this->app['config']->set('laravel-flow.queue.lock_store', 'file');
        $this->app->forgetInstance(FlowEngine::class);

        return $this->app->make(FlowEngine::class);
    }
}
