<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\GraphDefinition;

/**
 * Pure, framework-free readiness computation for the graph executor. Given a
 * graph and the current per-node states, it deterministically computes which
 * pending nodes are ready to run (all predecessors succeeded/skipped) and which
 * are newly blocked (at least one predecessor is poisoned). Blocked propagation
 * advances one level per pass; the coordinator re-runs it after every terminal
 * node so poison spreads transitively across passes.
 *
 * @api
 */
final class ReadinessResolver
{
    /**
     * @param  array<string, NodeState>  $states  node id => state; absent nodes are treated as Pending
     */
    public function resolve(GraphDefinition $graph, array $states): ReadinessDecision
    {
        $ready = [];
        $blocked = [];
        $stateOf = static fn (string $id): NodeState => $states[$id] ?? NodeState::Pending;

        /** @var array<string, list<string>> $predecessors */
        $predecessors = [];
        foreach ($graph->connections as $wire) {
            $predecessors[$wire->targetNodeId][] = $wire->sourceNodeId;
        }

        foreach ($graph->topologicalOrder() as $id) {
            if ($stateOf($id) !== NodeState::Pending) {
                continue; // in-flight or terminal
            }

            $anyPoisoned = false;
            $allSatisfied = true;
            foreach ($predecessors[$id] ?? [] as $predecessor) {
                $predecessorState = $stateOf($predecessor);
                if (in_array($predecessorState, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true)) {
                    $anyPoisoned = true;
                } elseif (! in_array($predecessorState, [NodeState::Succeeded, NodeState::Skipped], true)) {
                    $allSatisfied = false; // a predecessor is still pending/running/paused
                }
            }

            if ($anyPoisoned) {
                $blocked[] = $id;
            } elseif ($allSatisfied) {
                $ready[] = $id; // roots (no predecessors) fall here
            }
        }

        $allTerminal = true;
        foreach ($graph->nodeIds() as $id) {
            if (! $stateOf($id)->isTerminal()) {
                $allTerminal = false;
                break;
            }
        }

        return new ReadinessDecision($ready, $blocked, $allTerminal);
    }
}
