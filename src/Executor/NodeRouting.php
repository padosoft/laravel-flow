<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;

/**
 * Routing helpers shared by the synchronous {@see GraphRunner} and the queued
 * {@see Jobs\NodeJob} so both build a node's execution context identically:
 * deterministic incoming-wire ordering and root run-input seeding.
 *
 * @internal
 */
final class NodeRouting
{
    /**
     * Incoming wires for a node, ordered by the source node's topological index
     * (NOT the graph's raw connection-array order) so a `multiple` (fan-in)
     * port coalesces deterministically regardless of how the graph JSON/Studio
     * serialized its connections. Two wires sharing the same source index (two
     * output ports of the SAME source node both wired into this node) break
     * the tie on {@see Connection::identity()} — a canonical string derived
     * from the connection's own endpoints, not the incidental position it
     * happened to occupy in the serialized connections array — so re-importing
     * or re-saving a semantically-identical graph can never silently reorder a
     * fan-in list (which would change both the node's actual input AND, for a
     * `#[Cacheable]` node, its content hash).
     *
     * @param  array<string, int>  $sequenceOf  node id => topological index
     * @return list<Connection>
     */
    public static function connectionsInto(GraphDefinition $graph, string $nodeId, array $sequenceOf): array
    {
        $wires = array_values(array_filter(
            $graph->connections,
            static fn (Connection $c): bool => $c->targetNodeId === $nodeId,
        ));

        usort($wires, static function (Connection $a, Connection $b) use ($sequenceOf): int {
            $bySourceIndex = ($sequenceOf[$a->sourceNodeId] ?? 0) <=> ($sequenceOf[$b->sourceNodeId] ?? 0);

            return $bySourceIndex !== 0 ? $bySourceIndex : $a->identity() <=> $b->identity();
        });

        return $wires;
    }

    /**
     * A root node (no incoming wire) receives the run input on the conventional
     * `input` port — how the compiled v1 first step reads the flow input, and a
     * harmless no-op for graph nodes without an `input` port (the router only
     * reads config for ports the node actually has). An explicit `input` config
     * on the node always wins.
     *
     * @param  array<string, mixed>  $input
     */
    public static function seedRootInput(GraphNode $node, bool $isRoot, array $input): GraphNode
    {
        if (! $isRoot || array_key_exists('input', $node->config)) {
            return $node;
        }

        return new GraphNode($node->id, $node->type, ['input' => $input] + $node->config, $node->position);
    }

    /**
     * Node ids that have at least one incoming wire (i.e. are NOT roots).
     *
     * @return array<string, true>
     */
    public static function nodesWithIncoming(GraphDefinition $graph): array
    {
        $hasIncoming = [];

        foreach ($graph->connections as $wire) {
            $hasIncoming[$wire->targetNodeId] = true;
        }

        return $hasIncoming;
    }
}
