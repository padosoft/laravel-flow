<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * Pure, framework-free readiness computation for the graph executor. Given a
 * graph and the current per-node states, it deterministically computes which
 * pending nodes are ready to run (all predecessors succeeded/skipped), which
 * are newly blocked (at least one predecessor is poisoned) and which are
 * branch-skipped. Blocked propagation advances one level per pass; the
 * coordinator re-runs it after every terminal node so poison spreads
 * transitively across passes.
 *
 * Branching: `$activePorts` maps a node id to the output ports it activated
 * (see {@see NodeResult::branch()}). A wire from a
 * settled node that recorded an active-port list, on a port outside it, is
 * dead. A pending node with at least one incoming wire, all of them dead, is
 * skipped — and, in the same pass, so is everything downstream that only
 * depended on it. A node with any live wire still runs. A node with no
 * recorded list (every ordinary node, and a dry-run skip) keeps all its ports
 * live, so an empty map reproduces the historical decisions exactly.
 *
 * @api
 */
final class ReadinessResolver
{
    /**
     * @param  array<string, NodeState>  $states  node id => state; absent nodes are treated as Pending
     * @param  array<string, list<string>>  $activePorts  node id => output ports it activated (branching nodes only)
     */
    public function resolve(GraphDefinition $graph, array $states, array $activePorts = []): ReadinessDecision
    {
        $ready = [];
        $blocked = [];
        $skipped = [];

        // Nodes skipped by THIS pass count as settled for the nodes after them
        // in topological order, so a dead branch collapses in one pass; the
        // caller-supplied $states stay untouched (they drive allTerminal below,
        // and a skipped-but-not-yet-persisted node must not look terminal).
        $effective = $states;
        $stateOf = static function (string $id) use (&$effective): NodeState {
            return $effective[$id] ?? NodeState::Pending;
        };

        /** @var array<string, list<Connection>> $incoming */
        $incoming = [];
        foreach ($graph->connections as $wire) {
            $incoming[$wire->targetNodeId][] = $wire;
        }

        foreach ($graph->topologicalOrder() as $id) {
            if ($stateOf($id) !== NodeState::Pending) {
                continue; // in-flight or terminal
            }

            $anyPoisoned = false;
            $allSatisfied = true;
            $allDead = isset($incoming[$id]);
            foreach ($incoming[$id] ?? [] as $wire) {
                $predecessorState = $stateOf($wire->sourceNodeId);
                if (in_array($predecessorState, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true)) {
                    $anyPoisoned = true;
                    $allDead = false;
                } elseif (! in_array($predecessorState, [NodeState::Succeeded, NodeState::Skipped], true)) {
                    $allSatisfied = false; // a predecessor is still pending/running/paused
                    $allDead = false;
                } elseif (! $this->isDead($wire, $activePorts)) {
                    $allDead = false;
                }
            }

            if ($anyPoisoned) {
                $blocked[] = $id;
            } elseif ($allSatisfied && $allDead) {
                $skipped[] = $id;
                $effective[$id] = NodeState::Skipped;
                $activePorts[$id] = []; // every port of a skipped node is dead
            } elseif ($allSatisfied) {
                $ready[] = $id; // roots (no predecessors) fall here
            }
        }

        $allTerminal = true;
        foreach ($graph->nodeIds() as $id) {
            if (! ($states[$id] ?? NodeState::Pending)->isTerminal()) {
                $allTerminal = false;
                break;
            }
        }

        return new ReadinessDecision($ready, $blocked, $allTerminal, $skipped);
    }

    /**
     * @param  array<string, list<string>>  $activePorts
     */
    private function isDead(Connection $wire, array $activePorts): bool
    {
        return isset($activePorts[$wire->sourceNodeId])
            && ! in_array($wire->sourcePortKey, $activePorts[$wire->sourceNodeId], true);
    }
}
