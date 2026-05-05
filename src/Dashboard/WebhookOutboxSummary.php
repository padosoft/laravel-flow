<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;

/**
 * Stable read DTO representing one webhook outbox row for dashboard consumption.
 */
final readonly class WebhookOutboxSummary
{
    public function __construct(
        public int $id,
        public ?string $runId,
        public ?string $approvalId,
        public string $event,
        public string $status,
        public int $attempts,
        public int $maxAttempts,
        public ?DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $deliveredAt,
        public ?DateTimeImmutable $failedAt,
        public ?string $lastError,
    ) {}
}
