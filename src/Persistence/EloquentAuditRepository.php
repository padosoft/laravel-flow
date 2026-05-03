<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;

final class EloquentAuditRepository implements AuditRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function append(
        string $runId,
        string $event,
        array $payload = [],
        ?string $stepName = null,
        ?array $businessImpact = null,
        ?DateTimeInterface $occurredAt = null,
    ): FlowAuditRecord {
        $model = $this->newModel();
        $now = $model->freshTimestamp();
        $redact = fn (): array => [
            'business_impact' => $businessImpact === null ? null : $this->redactor->redact($businessImpact),
            'payload' => $this->redactor->redact($payload),
        ];
        $redacted = $this->redactor instanceof ExecutionScopedPayloadRedactor
            ? $this->redactor->usingCurrentRedactor($redact)
            : $redact();

        $model->forceFill([
            'business_impact' => $redacted['business_impact'],
            'created_at' => $now,
            'event' => $event,
            'occurred_at' => $occurredAt ?? $now,
            'payload' => $redacted['payload'],
            'run_id' => $runId,
            'step_name' => $stepName,
        ])->save();

        return $model->refresh();
    }

    public function forRun(string $runId): Collection
    {
        return $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get();
    }

    private function newModel(): FlowAuditRecord
    {
        return (new FlowAuditRecord)->setConnection($this->connection);
    }
}
