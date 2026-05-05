<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;

/**
 * @internal
 */
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
        $redactor = null;
        $redact = function (array $payload) use (&$redactor): array {
            $redactor ??= PayloadRedactorResolution::current($this->redactor);

            return $redactor->redact($payload);
        };

        $model->forceFill([
            'business_impact' => $businessImpact === null || $businessImpact === [] ? $businessImpact : $redact($businessImpact),
            'created_at' => $now,
            'event' => $event,
            'occurred_at' => $occurredAt ?? $now,
            'payload' => $payload === [] ? [] : $redact($payload),
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
