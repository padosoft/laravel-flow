<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;

final class EloquentWebhookOutboxRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPending(
        string $event,
        ?string $runId,
        ?string $approvalId,
        array $payload,
        ?DateTimeInterface $availableAt = null,
        int $maxAttempts = 3,
    ): FlowWebhookOutboxRecord {
        $model = $this->newModel();
        $model->forceFill($this->redact([
            'approval_id' => $approvalId,
            'attempts' => 0,
            'available_at' => $availableAt ?? $model->freshTimestamp(),
            'event' => $event,
            'max_attempts' => max(1, $maxAttempts),
            'payload' => $payload === [] ? null : $payload,
            'run_id' => $runId,
            'status' => FlowWebhookOutboxRecord::STATUS_PENDING,
        ]))->save();

        return $model->refresh();
    }

    private function newModel(): FlowWebhookOutboxRecord
    {
        return (new FlowWebhookOutboxRecord)->setConnection($this->connection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        if (! isset($attributes['payload']) || ! is_array($attributes['payload'])) {
            return $attributes;
        }

        $attributes['payload'] = $this->redactor->redact($attributes['payload']);

        return $attributes;
    }
}
