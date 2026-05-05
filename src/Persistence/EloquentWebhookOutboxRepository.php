<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;

final class EloquentWebhookOutboxRepository
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly ?string $connection,
        private readonly ?PayloadRedactor $redactor = null,
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
        ?PayloadRedactor $redactor = null,
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
        ], $redactor ?? $this->redactor))->save();

        return $model->refresh();
    }

    public function claimNextPending(DateTimeInterface $now, int $deliveryTimeoutSeconds = 0): ?FlowWebhookOutboxRecord
    {
        $query = $this->newQuery()
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query) use ($now): void {
                    $query->where('status', self::STATUS_PENDING)
                        ->where(fn (Builder $query) => $this->availableClause($query, $now, requireNotNullAvailableAt: false));
                });

                $query->orWhere(function (Builder $query) use ($now): void {
                    $query->where('status', self::STATUS_DELIVERING)
                        ->whereColumn('attempts', '<', 'max_attempts')
                        ->where('available_at', '<=', $now);
                });
            })
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('id');

        /** @var FlowWebhookOutboxRecord|null $candidate */
        $candidate = $query->first();

        if (! ($candidate instanceof FlowWebhookOutboxRecord)) {
            return null;
        }

        $updated = $this->newQuery()
            ->where('id', $candidate->id)
            ->where('attempts', $candidate->attempts)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->where(function (Builder $query) use ($now): void {
                $query->where(function (Builder $query) use ($now): void {
                    $query->where('status', self::STATUS_PENDING)
                        ->where(fn (Builder $query) => $this->availableClause($query, $now, requireNotNullAvailableAt: false));
                });

                $query->orWhere(function (Builder $query) use ($now): void {
                    $query->where('status', self::STATUS_DELIVERING)
                        ->where('available_at', '<=', $now);
                });
            })
            ->update([
                'attempts' => $candidate->attempts + 1,
                'status' => self::STATUS_DELIVERING,
                'available_at' => $this->nextLeaseAvailableAt($candidate->attempts + 1, $deliveryTimeoutSeconds),
                'updated_at' => $this->newModel()->freshTimestamp(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        /** @var FlowWebhookOutboxRecord|null $record */
        $record = $this->newQuery()
            ->where('id', $candidate->id)
            ->first();

        return $record;
    }

    public function markDeliveryResult(
        FlowWebhookOutboxRecord $record,
        string $status,
        int $attempts,
        DateTimeInterface $now,
        ?DateTimeInterface $nextAvailableAt = null,
        ?string $error = null,
    ): void {
        if (! in_array($status, [self::STATUS_DELIVERED, self::STATUS_FAILED, self::STATUS_PENDING], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported webhook outbox status [%s].', $status));
        }

        $payload = [
            'attempts' => $attempts,
            'status' => $status,
            'updated_at' => $this->newModel()->freshTimestamp(),
            'available_at' => $nextAvailableAt,
            'last_error' => $error,
        ];

        if ($status === self::STATUS_DELIVERED) {
            $payload['delivered_at'] = $now;
            $payload['failed_at'] = null;
            $payload['available_at'] = null;
            $payload['last_error'] = null;
        } elseif ($status === self::STATUS_FAILED) {
            $payload['failed_at'] = $now;
        }

        $this->newQuery()
            ->where('id', $record->id)
            ->where('status', self::STATUS_DELIVERING)
            ->where('attempts', $attempts)
            ->update($payload);
    }

    /**
     * @param  Builder<FlowWebhookOutboxRecord>  $query
     */
    private function availableClause(Builder $query, DateTimeInterface $now, bool $requireNotNullAvailableAt): void
    {
        if ($requireNotNullAvailableAt) {
            $query->whereNotNull('available_at')
                ->where('available_at', '<=', $now);

            return;
        }

        $query->whereNull('available_at')
            ->orWhere('available_at', '<=', $now);
    }

    private function nextLeaseAvailableAt(int $attempt, int $deliveryTimeoutSeconds = 0): DateTimeInterface
    {
        $attempt = max(1, $attempt);
        $seconds = 30 * (2 ** ($attempt - 1)) + max(0, $deliveryTimeoutSeconds);

        return $this->newModel()->freshTimestamp()->modify(sprintf('+%d seconds', $seconds));
    }

    private function newModel(): FlowWebhookOutboxRecord
    {
        return (new FlowWebhookOutboxRecord)->setConnection($this->connection);
    }

    /**
     * @return Builder<FlowWebhookOutboxRecord>
     */
    private function newQuery(): Builder
    {
        return $this->newModel()->newQuery();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes, ?PayloadRedactor $redactor = null): array
    {
        if (! isset($attributes['payload']) || ! is_array($attributes['payload'])) {
            return $attributes;
        }

        if (! ($redactor instanceof PayloadRedactor)) {
            return $attributes;
        }

        $attributes['payload'] = $redactor->redact($attributes['payload']);

        return $attributes;
    }
}
