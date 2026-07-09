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
     * @param  array<string, mixed>  $attributes
     */
    public function createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord;

    /**
     * @return Collection<int, FlowRunNodeRecord>
     */
    public function forRun(string $runId): Collection;
}
