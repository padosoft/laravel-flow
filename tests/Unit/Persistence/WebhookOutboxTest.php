<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
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
