<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Models\FlowNodeChildRecord;

/**
 * Suspend/join ledger for fan-out and sub-flow control nodes: it records the
 * child graph runs a parent node spawned so the parent can resume EXACTLY once
 * when the last child terminates (the join is driven by the executor's join
 * coordinator, which counts non-terminal children under a per-parent lock).
 *
 * @internal
 */
interface NodeChildRepository
{
    /**
     * Record a spawned child run for a parent fan-out/sub-flow node (status
     * `running`). `child_index` orders the join output.
     */
    public function record(string $runId, string $parentNodeId, string $childRunId, int $childIndex, DateTimeInterface $startedAt): FlowNodeChildRecord;

    /**
     * The ledger row for a spawned child run, or null if the run is not a child
     * of any parent node. Used by the queued finalizer to decide whether a
     * finishing run should drive a parent join.
     */
    public function findByChildRun(string $childRunId): ?FlowNodeChildRecord;

    /**
     * Compare-and-set a child to a terminal status (with its output), only when
     * the row is not already terminal. Returns true for the single caller that
     * transitioned it, so a duplicate child-completion is a no-op.
     *
     * @param  array<string, mixed>|null  $outputs
     */
    public function completeChild(string $childRunId, string $status, ?array $outputs, DateTimeInterface $finishedAt): bool;

    /**
     * All children of a parent node, ordered by `child_index` — the ordered set
     * the join aggregates and counts for completeness.
     *
     * @return Collection<int, FlowNodeChildRecord>
     */
    public function forParent(string $runId, string $parentNodeId): Collection;
}
