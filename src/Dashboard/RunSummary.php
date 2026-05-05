<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;

/**
 * Stable read DTO representing a single persisted flow run for dashboard consumption.
 *
 * The companion dashboard app must depend only on this DTO, never on
 * the underlying Eloquent record, to keep the read contract stable.
 */
final readonly class RunSummary
{
    public function __construct(
        public string $id,
        public string $definitionName,
        public string $status,
        public bool $dryRun,
        public ?string $failedStep,
        public bool $compensated,
        public ?string $compensationStatus,
        public ?string $correlationId,
        public ?string $idempotencyKey,
        public ?string $replayedFromRunId,
        public ?int $durationMs,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}
}
