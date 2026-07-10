<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Models\FlowNodeChildRecord;

/**
 * Suspend/join ledger for fan-out and sub-flow control nodes. Every item is
 * first recorded as a `pending` row (holding the child flow + item input); the
 * control node spawns only up to `maxConcurrency` of them (each `activate`d to
 * `running` with its child run id), and the join releases the next pending item
 * as each running child terminates — so in-flight children never exceed the cap.
 * The parent resumes EXACTLY once when the last child terminates.
 *
 * @internal
 */
interface NodeChildRepository
{
    /**
     * Record an item to run as a `pending` child (no child run yet). `input` is
     * the item's child-run input; `childFlow`/`childVersion` let the join spawn
     * it later.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordPending(string $runId, string $parentNodeId, int $childIndex, string $childFlow, ?int $childVersion, array $input): FlowNodeChildRecord;

    /**
     * Compare-and-set a `pending` item to `running` for a just-spawned child run
     * (stamping `child_run_id` + `started_at`). Returns true for the single
     * caller that won it, so an item is spawned at most once.
     */
    public function activate(string $runId, string $parentNodeId, int $childIndex, string $childRunId, DateTimeInterface $startedAt): bool;

    /**
     * The lowest-`child_index` still-`pending` item for a parent, or null when
     * none remain — the next item the join should release.
     */
    public function nextPending(string $runId, string $parentNodeId): ?FlowNodeChildRecord;

    /**
     * Count the not-yet-terminal children of a parent (`pending` + `running`) —
     * zero means the fan-out is complete and the parent may resume.
     */
    public function countUnfinished(string $runId, string $parentNodeId): int;

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
