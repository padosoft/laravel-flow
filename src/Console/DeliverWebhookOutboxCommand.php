<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;
use Padosoft\LaravelFlow\Persistence\EloquentWebhookOutboxRepository;
use Padosoft\LaravelFlow\WebhookDeliveryClient;
use Padosoft\LaravelFlow\WebhookDeliveryResult;

/**
 * @internal
 */
final class DeliverWebhookOutboxCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:deliver-webhooks
        {--batch=5 : Maximum number of webhook rows to process in this command run}
        {--sleep-ms=0 : Sleep (in ms) between failed delivery retries. Use 0 for immediate retry scheduling}';

    /**
     * @var string
     */
    protected $description = 'Deliver pending webhook outbox rows.';

    public function __construct(
        private readonly EloquentWebhookOutboxRepository $outbox,
        private readonly WebhookDeliveryClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) $this->getLaravel()->make('config')->get('laravel-flow.webhook.enabled', false)) {
            $this->error('Enable laravel-flow.webhook.enabled before delivering webhook outbox rows.');

            return self::FAILURE;
        }

        $url = (string) $this->getLaravel()->make('config')->get('laravel-flow.webhook.url', '');

        if ($url === '') {
            $this->error('Set laravel-flow.webhook.url before delivering webhooks.');

            return self::FAILURE;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Set laravel-flow.webhook.url to a valid URL before delivering webhooks.');

            return self::FAILURE;
        }

        $batch = $this->positiveIntOption('batch');
        if ($batch === null || $batch < 1) {
            $this->error('Use --batch as a positive integer.');

            return self::FAILURE;
        }

        $sleepMilliseconds = $this->nonNegativeIntOption('sleep-ms');
        if ($sleepMilliseconds === null) {
            $this->error('Use --sleep-ms as a non-negative integer.');

            return self::FAILURE;
        }

        $processed = 0;
        $delivered = 0;
        $failed = 0;

        $configTimeout = $this->getLaravel()->make('config')->get('laravel-flow.webhook.timeout_seconds', 5);
        $deliveryTimeoutSeconds = is_numeric($configTimeout) && (int) $configTimeout >= 1 ? (int) $configTimeout : 5;

        while ($processed < $batch) {
            try {
                $record = $this->outbox->claimNextPending($this->now(), $deliveryTimeoutSeconds);
            } catch (QueryException $e) {
                $this->error('Laravel Flow webhook outbox tables were not found or could not be queried.');
                if ($this->output->isVerbose()) {
                    $this->line($e->getMessage());
                }

                return self::FAILURE;
            }

            if (! ($record instanceof FlowWebhookOutboxRecord)) {
                break;
            }

            $processed++;

            $result = $this->deliverRecord($record, $url);
            $processedSuccessfully = $result->success;
            if ($processedSuccessfully) {
                $delivered++;
            } else {
                $failed++;
            }

            if ($sleepMilliseconds > 0 && $processed < $batch && $processedSuccessfully === false) {
                usleep($sleepMilliseconds * 1000);
            }
        }

        if ($processed === 0) {
            $this->info('No webhook outbox rows were available.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Processed %d webhook outbox row(s): %d delivered, %d failed.', $processed, $delivered, $failed));

        return self::SUCCESS;
    }

    private function deliverRecord(FlowWebhookOutboxRecord $record, string $url): WebhookDeliveryResult
    {
        $attempts = max($record->attempts, 1);
        $maxAttempts = max(1, $record->max_attempts);
        $payload = is_array($record->payload) ? $record->payload : [];
        $result = $this->client->deliver($url, $payload);

        if ($result->success) {
            $this->outbox->markDeliveryResult(
                $record,
                EloquentWebhookOutboxRepository::STATUS_DELIVERED,
                $attempts,
                $this->now(),
            );

            $this->info(sprintf('Delivered webhook outbox row [%d] for event [%s].', $record->id, $record->event));

            return $result;
        }

        if ($attempts >= $maxAttempts) {
            $this->outbox->markDeliveryResult(
                $record,
                EloquentWebhookOutboxRepository::STATUS_FAILED,
                $attempts,
                $this->now(),
                null,
                $result->error,
            );

            $this->warn(sprintf('Webhook outbox row [%d] for event [%s] is marked failed after %d attempts.', $record->id, $record->event, $attempts));

            return $result;
        }

        $this->outbox->markDeliveryResult(
            $record,
            EloquentWebhookOutboxRepository::STATUS_PENDING,
            $attempts,
            $this->now(),
            $this->nextAvailableAt($attempts),
            $result->error,
        );

        $this->warn(sprintf(
            'Webhook outbox row [%d] for event [%s] failed (attempt %d/%d), retry scheduled.',
            $record->id,
            $record->event,
            $attempts,
            $maxAttempts,
        ));

        return $result;
    }

    private function nextAvailableAt(int $attempts): DateTimeImmutable
    {
        $attempts = max(1, $attempts);
        $delaySeconds = 2 ** ($attempts - 1);
        $configDelay = $this->getLaravel()->make('config')->get('laravel-flow.webhook.retry_base_delay_seconds', 30);

        if (is_numeric($configDelay) && (int) $configDelay > 0) {
            $delaySeconds = max(1, (int) $configDelay) * $delaySeconds;
        }

        return $this->now()->modify(sprintf('+%d seconds', $delaySeconds));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (! is_string($value) || ! preg_match('/^[0-9]+$/', $value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized >= 1 ? $normalized : null;
    }

    private function nonNegativeIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! preg_match('/^[0-9]+$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
