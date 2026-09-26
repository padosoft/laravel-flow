<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * Optional {@see RunNodeRepository} extension for branching graphs
 * ({@see NodeResult::branch()}). The queued coordinator reads it to learn
 * which output ports each settled node activated, so it can skip the branches
 * that were not taken. A repository that does not implement it simply cannot
 * persist branch decisions: the coordinator falls back to reading them from
 * {@see RunNodeRepository::forRun()} rows.
 *
 * @api
 */
interface BranchAwareRunNodeRepository
{
    /**
     * The activated output ports of every node in the run that recorded a
     * list (branching nodes and branch-skipped nodes, whose list is empty),
     * keyed by node id. Nodes that never branched are absent — all of their
     * ports are live.
     *
     * @return array<string, list<string>>
     */
    public function activePorts(string $runId): array;
}
