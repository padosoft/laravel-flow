<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;

/**
 * Stable read DTO representing one append-only audit row for dashboard consumption.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public int $id,
        public string $runId,
        public ?string $stepName,
        public string $event,
        public DateTimeImmutable $occurredAt,
        public ?array $payload,
    ) {}
}
