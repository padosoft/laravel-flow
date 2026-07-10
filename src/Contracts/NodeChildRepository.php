<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Models\FlowNodeChildRecord;

/**
 * Suspend/join ledger for fan-out and sub-flow control nodes. Every item is
 * first recorded as a `pending` row (holding the child flow + item input); the
 * control node spawns only up to `maxConcurrency` of them (each claimed to
 * `running` BEFORE its child run is dispatched), and the join releases the next
 * pending item as each running child terminates — so in-flight children never
 * exceed the cap. The parent resumes EXACTLY once when the last child terminates.
 *
 * @internal
 */
interface NodeChildRepository
{
    /**
     * Record an item to run as a `pending` child (no run yet). `input` is the
     * item's child-run input; `childFlow`/`childVersion` let a later caller spawn
     * it.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordPending(string $runId, string $parentNodeId, int $childIndex, string $childFlow, ?int $childVersion, array $input): FlowNodeChildRecord;

    /**
     * Atomically claim the lowest-`child_index` still-`pending` child of a parent
     * and flip it to `running` (stamping `started_at`, no child run id yet),
     * returning the claimed row — or null when none remain. Selected
     * `lockForUpdate` so two concurrent spawners can never claim the same slot;
     * MUST be called inside a transaction, and the caller dispatches the child +
     * calls {@see self::attachChildRun()} in that SAME transaction so a dispatch
     * failure rolls the claim back to `pending`. The claim (not any cache lock) is
     * the mutual-exclusion primitive across the control node's initial burst and
     * the join's release.
     */
    public function claimNextPending(string $runId, string $parentNodeId, DateTimeInterface $startedAt): ?FlowNodeChildRecord;

    /**
     * Stamp the spawned child run id onto a row the caller already exclusively
     * owns (it won {@see self::claimNextPending()}); no CAS needed.
     */
    public function attachChildRun(string $runId, string $parentNodeId, int $childIndex, string $childRunId): void;

    /**
     * Count the not-yet-terminal children of a parent (`pending` + `running`) —
     * zero means the fan-out is complete and the parent may resume.
     */
    public function countUnfinished(string $runId, string $parentNodeId): int;

    /**
     * Count the currently in-flight (`running`) children of a parent — used by
     * the control node's initial burst to cap concurrency, so a retried burst
     * does not over-spawn past `maxConcurrency`.
     */
    public function countRunning(string $runId, string $parentNodeId): int;

    /**
     * The ledger row for a spawned child run, or null if the run is not a child.
     */
    public function findByChildRun(string $childRunId): ?FlowNodeChildRecord;

    /**
     * Compare-and-set a `running` child to a terminal status (with its output),
     * only when not already terminal. Returns true for the single caller that
     * transitioned it.
     *
     * @param  array<string, mixed>|null  $outputs
     */
    public function completeChild(string $childRunId, string $status, ?array $outputs, DateTimeInterface $finishedAt): bool;

    /**
     * All children of a parent, ordered by `child_index` — the ordered set the
     * join aggregates.
     *
     * @return Collection<int, FlowNodeChildRecord>
     */
    public function forParent(string $runId, string $parentNodeId): Collection;
}
