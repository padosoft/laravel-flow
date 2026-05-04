<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

final class WebhookOutboxTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_paused_run_persists_pending_webhook_outbox_row(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.webhook-outbox-paused')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $pausedRun = $engine->execute('flow.persist.webhook-outbox-paused', []);

        $this->assertSame(1, FlowWebhookOutboxRecord::query()->count());

        $record = FlowWebhookOutboxRecord::query()->firstOrFail();
        $this->assertSame($pausedRun->id, $record->run_id);
        $this->assertSame('flow.paused', $record->event);
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_PENDING, $record->status);
        $this->assertSame('paused', $record->payload['status']);
        $this->assertSame($pausedRun->id, $record->payload['flow_run_id']);
        $this->assertSame($pausedRun->approvalTokens['manager']->approvalId, $record->approval_id);
    }

    public function test_completed_run_persists_flow_completed_outbox_row(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.webhook-outbox-completed')
            ->step('create', AlwaysSucceedsHandler::class)
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.webhook-outbox-completed', []);
        $record = FlowWebhookOutboxRecord::query()->firstOrFail();

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_PENDING, $record->status);
        $this->assertSame('flow.completed', $record->event);
        $this->assertSame('succeeded', $record->payload['status']);
        $this->assertSame($run->id, $record->payload['flow_run_id']);
        $this->assertSame($run->id, $record->run_id);
        $this->assertNull($record->approval_id);
    }

    public function test_failed_run_persists_flow_failed_outbox_row(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.webhook-outbox-failed')
            ->step('create', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.persist.webhook-outbox-failed', []);
        $record = FlowWebhookOutboxRecord::query()->firstOrFail();

        $this->assertSame(FlowRun::STATUS_FAILED, $run->status);
        $this->assertSame('flow.failed', $record->event);
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_PENDING, $record->status);
        $this->assertSame('failed', $record->payload['status']);
        $this->assertSame('create', $record->payload['step_name']);
        $this->assertSame('RuntimeException', $record->payload['error_class']);
        $this->assertSame(FlowRun::STATUS_FAILED, $run->status);
        $this->assertSame('boom', $record->payload['error_message']);
    }

    public function test_approval_resume_persists_flow_resumed_outbox_row(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.webhook-outbox-resumed')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->step('publish', AlwaysSucceedsHandler::class)
            ->register();

        $pausedRun = $engine->execute('flow.persist.webhook-outbox-resumed', []);
        $resumeToken = $pausedRun->approvalTokens['manager']->plainTextToken;
        $resumedRun = $engine->resume($resumeToken);
        $events = FlowWebhookOutboxRecord::query()
            ->where('run_id', $pausedRun->id)
            ->pluck('event')
            ->all();

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $resumedRun->status);
        $this->assertContains('flow.paused', $events);
        $this->assertContains('flow.resumed', $events);
        $this->assertContains('flow.completed', $events);

        $resumedRecord = FlowWebhookOutboxRecord::query()
            ->where('event', 'flow.resumed')
            ->where('run_id', $pausedRun->id)
            ->firstOrFail();

        $this->assertSame('running', $resumedRecord->payload['status']);
        $this->assertSame('manager', $resumedRecord->payload['step_name']);
        $this->assertSame($pausedRun->approvalTokens['manager']->approvalId, $resumedRecord->approval_id);
    }

    public function test_approval_reject_persists_flow_failed_outbox_row(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $engine->define('flow.persist.webhook-outbox-rejected')
            ->step('create', AlwaysSucceedsHandler::class)
            ->approvalGate('manager')
            ->register();

        $pausedRun = $engine->execute('flow.persist.webhook-outbox-rejected', []);
        $rejectToken = $pausedRun->approvalTokens['manager']->plainTextToken;
        $rejectedRun = $engine->reject($rejectToken);

        $failedRecord = FlowWebhookOutboxRecord::query()
            ->where('event', 'flow.failed')
            ->where('run_id', $pausedRun->id)
            ->firstOrFail();
        $approvalRecord = FlowApprovalRecord::query()
            ->where('run_id', $pausedRun->id)
            ->where('step_name', 'manager')
            ->firstOrFail();

        $this->assertSame('failed', $failedRecord->payload['status']);
        $this->assertSame('manager', $failedRecord->payload['step_name']);
        $this->assertSame($approvalRecord->id, $failedRecord->approval_id);
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_PENDING, $failedRecord->status);
        $this->assertSame(FlowRun::STATUS_FAILED, $rejectedRun->status);
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
