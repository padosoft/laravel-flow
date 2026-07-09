<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Illuminate\Database\Eloquent\Collection;
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
}
