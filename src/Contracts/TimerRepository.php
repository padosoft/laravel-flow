<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeImmutable;
use DateTimeInterface;
use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * Optional {@see RunNodeRepository} extension for timed pauses
 * ({@see NodeResult::pausedUntil()}). A timer is a `paused` node row that also
 * carries a `resume_at`; the engine resumes it when it falls due. A repository
 * that does not implement this contract simply cannot resume timers: the
 * resume job logs and stops, and `flow:resume-due-timers` reports it.
 *
 * @api
 */
interface TimerRepository
{
    /**
     * Timers whose `resume_at` has passed, oldest first. Only rows that are
     * still `paused` count, so a cancelled run's node is never returned.
     *
     * @return list<array{run_id: string, node_id: string}>
     */
    public function dueTimers(DateTimeInterface $now, int $limit): array;

    /**
     * The `resume_at` of a node that is still `paused` on a timer, or null when
     * the node is not paused or has no timer (an approval pause, an already
     * resumed or cancelled node).
     */
    public function pendingTimer(string $runId, string $nodeId): ?DateTimeImmutable;

    /**
     * Atomically complete a due timer: a compare-and-set that flips the node
     * `paused` -> `succeeded` (keeping its stored outputs, clearing `resume_at`
     * and any error fields) ONLY while it is still `paused` with a `resume_at`
     * at or before `$now`. Returns true for the single writer that won, false
     * for a duplicate, a late job, a cancelled node or a timer not yet due.
     */
    public function resumeTimer(string $runId, string $nodeId, DateTimeInterface $now): bool;
}
