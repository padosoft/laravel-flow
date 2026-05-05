<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;

/**
 * @api
 */
interface AuditRepository
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $businessImpact
     */
    public function append(
        string $runId,
        string $event,
        array $payload = [],
        ?string $stepName = null,
        ?array $businessImpact = null,
        ?DateTimeInterface $occurredAt = null,
    ): FlowAuditRecord;

    /**
     * @return Collection<int, FlowAuditRecord>
     */
    public function forRun(string $runId): Collection;
}
