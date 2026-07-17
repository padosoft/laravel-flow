<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Dashboard;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Dashboard\ApprovalFilter;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Dashboard\Pagination;
use Padosoft\LaravelFlow\Dashboard\RunFilter;
use Padosoft\LaravelFlow\Dashboard\WebhookOutboxFilter;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
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

    public function test_step_counts_returns_counts_per_run_in_a_single_query(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.step-counts-two')
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', AlwaysSucceedsHandler::class)
            ->register();
        $engine->define('flow.dashboard.step-counts-one')
            ->step('only', AlwaysSucceedsHandler::class)
            ->register();

        $twoStepRun = $engine->execute('flow.dashboard.step-counts-two', []);
        $oneStepRun = $engine->execute('flow.dashboard.step-counts-one', []);

        DB::connection()->enableQueryLog();

        $counts = $this->reader()->stepCounts([$twoStepRun->id, $oneStepRun->id, 'does-not-exist']);

        $queries = DB::connection()->getQueryLog();
        DB::connection()->flushQueryLog();

        $this->assertCount(1, $queries, 'stepCounts() must run exactly one grouped query regardless of how many run ids are requested.');
        $this->assertSame(2, $counts[$twoStepRun->id]);
        $this->assertSame(1, $counts[$oneStepRun->id]);
        $this->assertArrayNotHasKey('does-not-exist', $counts);
    }

    public function test_step_counts_returns_empty_array_for_empty_input(): void
    {
        $this->migrateFlowTables();

        $this->assertSame([], $this->reader()->stepCounts([]));
    }

    public function test_step_summary_projection_is_preserved_after_persistence_unification(): void
    {
        // Golden projection: a fixed v1 flow persisted onto the unified
        // flow_run_nodes table must still project the exact StepSummary shape
        // (name/sequence/status/handler) the Dashboard contract promised when
        // steps lived in flow_steps. This is the strongest signal that the
        // v1 rewire changed only the persistence target, not observable state.
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.golden')
            ->step('create', AlwaysSucceedsHandler::class)
            ->step('charge', AlwaysSucceedsHandler::class)
            ->step('notify', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.dashboard.golden', ['payload' => 'value']);
        $detail = $this->reader()->findRun($run->id);

        $this->assertNotNull($detail);

        $projection = array_map(
            static fn ($step): array => [
                'name' => $step->name,
                'sequence' => $step->sequence,
                'status' => $step->status,
                'handler' => $step->handler,
            ],
            $detail->steps,
        );

        $this->assertSame([
            ['name' => 'create', 'sequence' => 1, 'status' => 'succeeded', 'handler' => AlwaysSucceedsHandler::class],
            ['name' => 'charge', 'sequence' => 2, 'status' => 'succeeded', 'handler' => AlwaysSucceedsHandler::class],
            ['name' => 'notify', 'sequence' => 3, 'status' => 'succeeded', 'handler' => AlwaysSucceedsHandler::class],
        ], $projection);

        // A fresh (non-cached) run reports every step as a cache MISS.
        foreach ($detail->steps as $step) {
            $this->assertFalse($step->cacheHit);
        }
    }

    public function test_step_summary_reports_a_cache_hit_from_the_stored_column(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.cache-projection')
            ->step('fetch', AlwaysSucceedsHandler::class)
            ->step('render', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.dashboard.cache-projection', []);

        // Simulate the second node being served from the node cache: the
        // engine records the cache content hash in flow_run_nodes.cache_hit.
        // A realistic 64-hex content hash, matching what NodeCache stores.
        FlowRunNodeRecord::query()
            ->where('run_id', $run->id)
            ->where('node_id', 'render')
            ->update(['cache_hit' => str_repeat('a1b2c3d4e5f6a7b8', 4)]);

        $detail = $this->reader()->findRun($run->id);
        $this->assertNotNull($detail);

        $byName = [];
        foreach ($detail->steps as $step) {
            $byName[$step->name] = $step->cacheHit;
        }

        $this->assertFalse($byName['fetch']);
        $this->assertTrue($byName['render']);
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
        // The stored token HASH is surfaced (never the plain token) so a
        // dashboard can drive Flow::resumeByHash()/rejectByHash().
        $this->assertSame(
            ApprovalTokenManager::hashToken($paused->approvalTokens['manager']->plainTextToken),
            $pending[0]->tokenHash,
        );

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

    public function test_list_approvals_supports_status_filter_and_pagination(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.list-approvals')
            ->step('one', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $paused = $engine->execute('flow.dashboard.list-approvals', []);

        $reader = $this->reader();
        $allPending = $reader->listApprovals(
            new ApprovalFilter(status: FlowApprovalRecord::STATUS_PENDING),
            new Pagination(1, 25),
        );

        $this->assertSame(1, $allPending->total);
        $this->assertSame($paused->id, $allPending->items[0]->runId);
        $this->assertSame(FlowApprovalRecord::STATUS_PENDING, $allPending->items[0]->status);

        $approved = $engine->resume($paused->approvalTokens['manager']->plainTextToken);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $approved->status);

        $listApproved = $reader->listApprovals(
            new ApprovalFilter(status: FlowApprovalRecord::STATUS_APPROVED),
            new Pagination(1, 25),
        );
        $this->assertSame(1, $listApproved->total);

        $listPendingNow = $reader->listApprovals(
            new ApprovalFilter(status: FlowApprovalRecord::STATUS_PENDING),
            new Pagination(1, 25),
        );
        $this->assertSame(0, $listPendingNow->total);

        $listAll = $reader->listApprovals(new ApprovalFilter, new Pagination(1, 25));
        $this->assertSame(1, $listAll->total);
    }

    public function test_list_webhook_outbox_supports_status_event_run_filters_and_pagination(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.dashboard.outbox-success')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();
        $engine->define('flow.dashboard.outbox-fail')
            ->step('one', AlwaysFailsHandler::class)
            ->register();

        $successRun = $engine->execute('flow.dashboard.outbox-success', []);
        $failRun = $engine->execute('flow.dashboard.outbox-fail', []);

        // Mark the success run's outbox row as already delivered so the
        // status filter can prove it surfaces non-pending rows too.
        FlowWebhookOutboxRecord::query()
            ->where('run_id', $successRun->id)
            ->update([
                'status' => FlowWebhookOutboxRecord::STATUS_DELIVERED,
                'attempts' => 1,
                'delivered_at' => now(),
            ]);

        $reader = $this->reader();

        $delivered = $reader->listWebhookOutbox(
            new WebhookOutboxFilter(status: FlowWebhookOutboxRecord::STATUS_DELIVERED),
            new Pagination(1, 25),
        );
        $this->assertSame(1, $delivered->total);
        $this->assertSame($successRun->id, $delivered->items[0]->runId);
        $this->assertSame('flow.completed', $delivered->items[0]->event);

        $byEvent = $reader->listWebhookOutbox(
            new WebhookOutboxFilter(event: 'flow.failed'),
            new Pagination(1, 25),
        );
        $this->assertSame(1, $byEvent->total);
        $this->assertSame($failRun->id, $byEvent->items[0]->runId);

        $byRun = $reader->listWebhookOutbox(
            new WebhookOutboxFilter(runId: $successRun->id),
            new Pagination(1, 25),
        );
        $this->assertSame(1, $byRun->total);

        $all = $reader->listWebhookOutbox(new WebhookOutboxFilter, new Pagination(1, 25));
        $this->assertSame(2, $all->total);
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
