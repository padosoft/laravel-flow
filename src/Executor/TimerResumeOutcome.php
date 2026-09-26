<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use DateTimeImmutable;

/**
 * What {@see TimerResumer::resume()} did for one timer node: it `resumed` the
 * node (and re-entered the coordinator), found it `notDue` (with the time it is
 * due), or did nothing (`noop`: a duplicate, a late job, a cancelled run, a
 * node that is not a paused timer, or a repository without timer support).
 *
 * @internal
 */
final readonly class TimerResumeOutcome
{
    private function __construct(
        public string $status,
        public ?DateTimeImmutable $dueAt = null,
    ) {}

    public static function resumed(): self
    {
        return new self('resumed');
    }

    public static function notDue(DateTimeImmutable $dueAt): self
    {
        return new self('not_due', $dueAt);
    }

    public static function noop(): self
    {
        return new self('noop');
    }

    public function wasResumed(): bool
    {
        return $this->status === 'resumed';
    }

    public function isNotDue(): bool
    {
        return $this->status === 'not_due';
    }
}
