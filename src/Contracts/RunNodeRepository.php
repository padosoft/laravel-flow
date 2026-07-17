<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;

/**
 * Unified per-node persistence used by both the v1 linear engine (a step is a
 * node with node_type 'legacy.step') and the graph executor (a node is a node).
 * Supersedes the retired StepRunRepository.
 *
 * @api
 */
interface RunNodeRepository
{
    /**
     * Persist a node record using the run id and node id as immutable identity.
     *
     * `$attributes` MUST include a non-null `node_type` (the `flow_run_nodes`
     * column is NOT NULL) — `'legacy.step'` for a v1 linear step, the real node
     * type for a graph node. `run_id` / `node_id` / `id` keys are ignored (the
     * method arguments are the source of truth). JSON payload keys (`inputs`,
     * `outputs`, `business_impact`) are redacted before write.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord;

    /**
     * All node rows for a run, ordered by `sequence` then `id` — a stable order
     * the v1 engine relies on to reconstruct step sequence (approval recovery,
     * replay drift checks). Rows with a null `sequence` sort before sequenced
     * rows, ties broken by insertion order (`id`).
     *
     * @return Collection<int, FlowRunNodeRecord>
     */
    public function forRun(string $runId): Collection;

    /**
     * Current state of every persisted node row for a run, keyed by node id.
     * The queued coordinator reads this snapshot to drive readiness. A node
     * with no row yet is simply absent (the readiness resolver treats an
     * absent node as {@see NodeState::Pending}).
     *
     * @return array<string, NodeState>
     */
    public function states(string $runId): array;

    /**
     * Atomically claim a pending node for execution: a compare-and-set that
     * flips `pending` -> `running` (stamping `started_at`) only when the row is
     * still `pending`. Returns true for the single writer that won the claim
     * and false for every other coordinator pass, so a node is dispatched at
     * most once even under duplicate coordinator delivery.
     */
    public function claim(string $runId, string $nodeId, DateTimeInterface $startedAt): bool;

    /**
     * Release a claim: the inverse compare-and-set that flips `running` ->
     * `pending` (clearing `started_at`) only when the row is still `running`.
     * The coordinator uses this to make a claimed node re-claimable when the
     * node job it just claimed could not be enqueued, so a retry re-dispatches
     * it instead of leaving the run stuck. Returns true iff the reset happened.
     */
    public function releaseClaim(string $runId, string $nodeId): bool;

    /**
     * Atomically move a still-active node to a terminal state: a compare-and-set
     * that writes `$newStatus` (+ `finished_at`/`duration_ms`) ONLY when the row
     * is still in `$expectedStatus`. Returns true iff the row was in that status
     * and moved. `cancel()` uses this so a node a concurrent queued job just
     * completed (e.g. `running` -> `succeeded`) is left untouched rather than
     * clobbered back to `failed`/`skipped` — a node whose status has already
     * moved on matches zero rows and returns false.
     */
    public function terminate(string $runId, string $nodeId, string $expectedStatus, string $newStatus, DateTimeInterface $finishedAt, ?int $durationMs): bool;
}
