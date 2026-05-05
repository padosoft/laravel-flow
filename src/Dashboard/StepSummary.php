<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;

/**
 * Stable read DTO representing a persisted step row for dashboard consumption.
 */
final readonly class StepSummary
{
    public function __construct(
        public int $id,
        public string $runId,
        public string $name,
        public string $handler,
        public int $sequence,
        public string $status,
        public ?string $errorClass,
        public ?string $errorMessage,
        public ?int $durationMs,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}
}
