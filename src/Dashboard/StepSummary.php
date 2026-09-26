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
        /**
         * The output ports a branching node activated, `[]` for a node skipped
         * because every incoming wire was dead (a branch not taken), and null
         * for every ordinary node (all ports live). Lets a run view grey out
         * the branch that was not taken.
         *
         * @var list<string>|null
         */
        public ?array $activePorts = null,
        /**
         * When a node paused on a timer (`NodeResult::pausedUntil()`, e.g. a
         * delay node) is due to resume; null for every other node, including
         * one paused on an approval.
         */
        public ?DateTimeImmutable $resumeAt = null,
    ) {}
}
