<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;

/**
 * Stable read DTO representing one approval record for dashboard consumption.
 *
 * Plain approval tokens are never stored or exposed here; only redacted
 * actor metadata, decision payload, status, and timestamps are surfaced.
 */
final readonly class ApprovalSummary
{
    /**
     * @param  array<string, mixed>|null  $actor
     * @param  array<string, mixed>|null  $decisionPayload
     */
    public function __construct(
        public string $id,
        public string $runId,
        public string $stepName,
        public string $status,
        public ?DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $decidedAt,
        public ?DateTimeImmutable $consumedAt,
        public ?array $actor,
        public ?array $decisionPayload,
    ) {}
}
