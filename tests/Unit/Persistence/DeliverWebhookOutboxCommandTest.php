<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use DateTimeImmutable;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
use Padosoft\LaravelFlow\Persistence\EloquentWebhookOutboxRepository;
use Padosoft\LaravelFlow\WebhookDeliveryClient;

final class DeliverWebhookOutboxCommandTest extends PersistenceTestCase
{
    public function test_deliver_webhook_command_marks_pending_rows_as_delivered_on_success(): void
    {
        $this->migrateFlowTables();

        $outbox = $this->app->make(EloquentWebhookOutboxRepository::class);
        $record = $outbox->createPending('flow.completed', null, null, ['status' => 'ok'], null, 3);

        $this->app->instance(
            WebhookDeliveryClient::class,
            new WebhookDeliveryClient(
                transport: static function (string $url, array $headers, string $body, int $timeout): array {
                    return [
                        'status_code' => 201,
                        'body' => '{"ok":true}',
                        'error' => '',
                    ];
                },
                timeoutSeconds: 2,
                secret: null,
            ),
        );

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks')
            ->expectsOutputToContain(sprintf(
                'Delivered webhook outbox row [%d] for event [flow.completed].',
                $record->id,
            ))
            ->assertExitCode(0);

        $record = FlowWebhookOutboxRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame(EloquentWebhookOutboxRepository::STATUS_DELIVERED, $record->status);
        $this->assertNotNull($record->delivered_at);
        $this->assertNull($record->failed_at);
        $this->assertNull($record->last_error);
    }

    public function test_deliver_webhook_command_reschedules_retryable_failures(): void
    {
        $this->migrateFlowTables();

        $outbox = $this->app->make(EloquentWebhookOutboxRepository::class);
        $record = $outbox->createPending('flow.failed', null, null, ['status' => 'retry'], null, 3);

        $this->app->instance(
            WebhookDeliveryClient::class,
            new WebhookDeliveryClient(
                transport: static function (string $url, array $headers, string $body, int $timeout): array {
                    return [
                        'status_code' => 500,
                        'body' => 'retry later',
                        'error' => 'temporary failure',
                    ];
                },
                timeoutSeconds: 2,
                secret: null,
            ),
        );

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');
        $this->app['config']->set('laravel-flow.webhook.retry_base_delay_seconds', 1);

        $this->artisan('flow:deliver-webhooks', ['--batch' => 1])
            ->expectsOutputToContain(sprintf(
                'Webhook outbox row [%d] for event [flow.failed] failed (attempt 1/3), retry scheduled.',
                $record->id,
            ))
            ->assertExitCode(0);

        $record = FlowWebhookOutboxRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame(EloquentWebhookOutboxRepository::STATUS_PENDING, $record->status);
        $this->assertSame(1, $record->attempts);
        $this->assertNotNull($record->available_at);
        $this->assertSame('temporary failure', $record->last_error);
    }

    public function test_deliver_webhook_command_marks_failures_as_failed_after_max_attempts(): void
    {
        $this->migrateFlowTables();

        $outbox = $this->app->make(EloquentWebhookOutboxRepository::class);
        $record = $outbox->createPending('flow.failed', null, null, ['status' => 'fatal'], null, 1);

        $this->app->instance(
            WebhookDeliveryClient::class,
            new WebhookDeliveryClient(
                transport: static function (string $url, array $headers, string $body, int $timeout): array {
                    return [
                        'status_code' => 500,
                        'body' => 'fatal failure',
                        'error' => 'fatal failure',
                    ];
                },
                timeoutSeconds: 2,
                secret: null,
            ),
        );

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks', ['--batch' => 1])
            ->expectsOutputToContain(sprintf(
                'Webhook outbox row [%d] for event [flow.failed] is marked failed after 1 attempts.',
                $record->id,
            ))
            ->assertExitCode(0);

        $record = FlowWebhookOutboxRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame(EloquentWebhookOutboxRepository::STATUS_FAILED, $record->status);
        $this->assertSame(1, $record->attempts);
        $this->assertNotNull($record->failed_at);
        $this->assertSame('fatal failure', $record->last_error);
    }

    public function test_deliver_webhook_command_fails_when_webhook_tables_are_missing(): void
    {
        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks')
            ->expectsOutputToContain('Laravel Flow webhook outbox tables were not found or could not be queried.')
            ->assertExitCode(1);
    }

    public function test_deliver_webhook_command_recovers_stale_delivering_rows(): void
    {
        $this->migrateFlowTables();

        $outbox = $this->app->make(EloquentWebhookOutboxRepository::class);
        $record = $outbox->createPending('flow.failed', null, null, ['status' => 'retry'], null, 3);

        FlowWebhookOutboxRecord::query()->whereKey($record->id)->update([
            'status' => EloquentWebhookOutboxRepository::STATUS_DELIVERING,
            'attempts' => 1,
            'available_at' => new DateTimeImmutable('-2 minutes'),
        ]);

        $this->app->instance(
            WebhookDeliveryClient::class,
            new WebhookDeliveryClient(
                transport: static function (string $url, array $headers, string $body, int $timeout): array {
                    return [
                        'status_code' => 200,
                        'body' => 'ok',
                        'error' => '',
                    ];
                },
                timeoutSeconds: 2,
                secret: null,
            ),
        );

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks', ['--batch' => 1])->assertExitCode(0);

        $record = FlowWebhookOutboxRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame(EloquentWebhookOutboxRepository::STATUS_DELIVERED, $record->status);
    }

    public function test_deliver_webhook_command_fails_when_webhook_url_is_blank(): void
    {
        $this->migrateFlowTables();

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', '');

        $this->artisan('flow:deliver-webhooks')
            ->expectsOutputToContain('Set laravel-flow.webhook.url before delivering webhooks.')
            ->assertExitCode(1);
    }

    public function test_deliver_webhook_command_fails_when_webhook_url_is_invalid(): void
    {
        $this->migrateFlowTables();

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'not-a-url');

        $this->artisan('flow:deliver-webhooks')
            ->expectsOutputToContain('Set laravel-flow.webhook.url to a valid URL before delivering webhooks.')
            ->assertExitCode(1);
    }

    public function test_deliver_webhook_command_rejects_non_positive_batch_option(): void
    {
        $this->migrateFlowTables();

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks', ['--batch' => '0'])
            ->expectsOutputToContain('Use --batch as a positive integer.')
            ->assertExitCode(1);
    }

    public function test_deliver_webhook_command_rejects_non_numeric_sleep_ms_option(): void
    {
        $this->migrateFlowTables();

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks', ['--sleep-ms' => 'abc'])
            ->expectsOutputToContain('Use --sleep-ms as a non-negative integer.')
            ->assertExitCode(1);
    }

    public function test_deliver_webhook_command_skips_future_delivering_rows(): void
    {
        $this->migrateFlowTables();

        $outbox = $this->app->make(EloquentWebhookOutboxRepository::class);
        $record = $outbox->createPending('flow.failed', null, null, ['status' => 'retry'], null, 3);

        FlowWebhookOutboxRecord::query()->whereKey($record->id)->update([
            'status' => EloquentWebhookOutboxRepository::STATUS_DELIVERING,
            'attempts' => 1,
            'available_at' => new DateTimeImmutable('+2 minutes'),
        ]);

        $this->app['config']->set('laravel-flow.webhook.enabled', true);
        $this->app['config']->set('laravel-flow.webhook.url', 'https://example.test/webhooks/flow');

        $this->artisan('flow:deliver-webhooks')
            ->expectsOutputToContain('No webhook outbox rows were available.')
            ->assertExitCode(0);

        $record = FlowWebhookOutboxRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame(EloquentWebhookOutboxRepository::STATUS_DELIVERING, $record->status);
    }
}
