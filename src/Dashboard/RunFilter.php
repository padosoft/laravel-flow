<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeInterface;

/**
 * Filter DTO for run-list dashboard queries. All fields are optional; null
 * means "do not constrain on this field". Filter values are matched as
 * exact equality (not LIKE) so the dashboard contract stays predictable.
 *
 * @api
 */
final readonly class RunFilter
{
    public function __construct(
        public ?string $definitionName = null,
        public ?string $status = null,
        public ?string $correlationId = null,
        public ?string $idempotencyKey = null,
        public ?bool $compensated = null,
        public ?DateTimeInterface $startedSince = null,
        public ?DateTimeInterface $startedUntil = null,
    ) {}
}
