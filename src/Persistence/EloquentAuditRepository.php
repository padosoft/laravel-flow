<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeImmutable;
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
        $model->forceFill([
            'business_impact' => $businessImpact === null ? null : $this->redactor->redact($businessImpact),
            'created_at' => new DateTimeImmutable,
            'event' => $event,
            'occurred_at' => $occurredAt ?? new DateTimeImmutable,
            'payload' => $this->redactor->redact($payload),
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
