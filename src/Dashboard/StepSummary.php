<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;

/**
 * Stable read DTO representing a persisted step row for dashboard consumption.
 *
 * @api
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
        /**
         * True when this step's result was served from the node cache
         * (`#[Cacheable]`/`NodeCache`) rather than re-executed. A cache hit
         * is metadata ON an otherwise-`succeeded` step, not a distinct
         * lifecycle status — dashboards render it as a badge/overlay on a
         * succeeded node, not as a separate state. The underlying column
         * stores the cache content hash (or null when not cached); this
         * boolean deliberately exposes only the hit/miss fact, never the
         * hash itself.
         */
        public bool $cacheHit = false,
    ) {}
}
