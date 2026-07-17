<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
use Padosoft\LaravelFlow\Persistence\EloquentWebhookOutboxRepository;
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

    public function test_redeliver_resets_a_failed_outbox_row_and_makes_it_claimable_again(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        // A row that has exhausted its attempts and is parked at `failed`.
        $record = FlowWebhookOutboxRecord::query()->create([
            'event' => 'flow.failed',
            'status' => FlowWebhookOutboxRecord::STATUS_FAILED,
            'attempts' => 3,
            'max_attempts' => 3,
            'available_at' => null,
            'failed_at' => now(),
            'last_error' => 'HTTP 500 from the receiver',
            'payload' => ['flow_run_id' => 'run-redeliver'],
        ]);

        $this->assertTrue($engine->redeliverWebhook((int) $record->id));

        $fresh = $record->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_PENDING, $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->failed_at);
        $this->assertNull($fresh->last_error);
        $this->assertNotNull($fresh->available_at);

        // Resetting attempts to 0 re-opens the `attempts < max_attempts` gate,
        // so the existing delivery path (claimNextPending) picks it up again.
        $repository = $this->app->make(EloquentWebhookOutboxRepository::class);
        $claimed = $repository->claimNextPending(now());
        $this->assertNotNull($claimed);
        $this->assertSame((int) $record->id, (int) $claimed->id);
    }

    public function test_redeliver_is_a_noop_for_a_non_failed_or_unknown_row(): void
    {
        $this->migrateFlowTables();
        $engine = $this->engineWithPersistence();

        $delivered = FlowWebhookOutboxRecord::query()->create([
            'event' => 'flow.completed',
            'status' => FlowWebhookOutboxRecord::STATUS_DELIVERED,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);

        // A delivered row must not be disturbed, and an unknown id is a no-op.
        $this->assertFalse($engine->redeliverWebhook((int) $delivered->id));
        $this->assertSame(FlowWebhookOutboxRecord::STATUS_DELIVERED, $delivered->fresh()?->status);
        $this->assertFalse($engine->redeliverWebhook(999999));
    }

    public function test_redeliver_requires_persistence_enabled(): void
    {
        $this->app['config']->set('laravel-flow.persistence.enabled', false);
        $this->app->forgetInstance(FlowEngine::class);
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        // With persistence off there is no outbox table — the @api method must
        // surface a stable typed failure, not a raw QueryException.
        $this->expectException(FlowExecutionException::class);
        $engine->redeliverWebhook(1);
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
