<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\DryRun;

use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Throwable;

/**
 * Static DAG dry-run: computes the execution plan (Kahn waves) and the cost
 * estimate (summed `#[Cost]` hints) for a {@see GraphDefinition} WITHOUT
 * executing any handler and WITHOUT touching persistence — zero rows are
 * written anywhere, by construction (nothing here holds a store).
 *
 * The plan is OPTIMISTIC: a node's dry-run self-skip is only knowable at run
 * time, so every node lands in a wave (see {@see ExecutionPlan::$skipped}).
 * Cost hints are read from the already-reflected {@see NodeDefinition}s in the
 * {@see NodeRegistry} — no handler is EVER constructed by the planner (a
 * container-built handler could run side-effectful constructors, breaking the
 * zero-work contract). A node whose type is unregistered, a compiled v1 step
 * (whose definition carries no `#[Cost]`), or a node without a hint simply
 * contributes no cost.
 *
 * @api
 */
final class DryRunPlanner
{
    public function __construct(
        private readonly NodeRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $input  reserved for future input-aware planning; the current planner is purely structural
     * @return array{plan: ExecutionPlan, cost: CostEstimate}
     */
    public function plan(GraphDefinition $graph, array $input = []): array
    {
        return [
            'plan' => new ExecutionPlan($this->waves($graph), []),
            'cost' => $this->cost($graph),
        ];
    }

    /**
     * Kahn layers over the validated DAG: wave 0 = roots (no incoming wire),
     * wave N = nodes whose predecessors all appear in earlier waves. Built off
     * `topologicalOrder()` (predecessors always precede their node), so each
     * node's wave is 1 + the max wave of its predecessors.
     *
     * @return list<list<string>>
     */
    private function waves(GraphDefinition $graph): array
    {
        /** @var array<string, list<string>> $predecessors */
        $predecessors = [];

        foreach ($graph->connections as $connection) {
            $predecessors[$connection->targetNodeId][] = $connection->sourceNodeId;
        }

        /** @var array<string, int> $waveOf */
        $waveOf = [];
        /** @var list<list<string>> $waves */
        $waves = [];

        foreach ($graph->topologicalOrder() as $id) {
            $wave = 0;

            foreach ($predecessors[$id] ?? [] as $sourceId) {
                $wave = max($wave, ($waveOf[$sourceId] ?? -1) + 1);
            }

            $waveOf[$id] = $wave;
            $waves[$wave][] = $id;
        }

        return $waves;
    }

    private function cost(GraphDefinition $graph): CostEstimate
    {
        /** @var array<string, array<string, int|float>> $perNode */
        $perNode = [];
        /** @var array<string, int|float> $total */
        $total = [];

        foreach ($graph->nodes as $node) {
            $estimate = $this->estimateFor($node);

            if ($estimate === null) {
                continue;
            }

            $perNode[$node->id] = $estimate;

            foreach ($estimate as $dimension => $amount) {
                $total[$dimension] = ($total[$dimension] ?? 0) + $amount;
            }
        }

        return new CostEstimate($perNode, $total);
    }

    /**
     * @return array<string, int|float>|null
     */
    private function estimateFor(GraphNode $node): ?array
    {
        // Definitions only, straight from the registry: constructing a handler
        // here (as NodeResolver would) could run side-effectful constructors,
        // breaking the planner's zero-work contract. A compiled v1 step's
        // definition carries no #[Cost], and an unknown type simply advertises
        // no cost — the planner is advisory and must never abort over a hint.
        if ($node->type === FlowDefinition::LEGACY_NODE_TYPE) {
            return null;
        }

        try {
            return $this->registry->get($node->type)->cost?->estimate;
        } catch (Throwable) {
            return null;
        }
    }
}
