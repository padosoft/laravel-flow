<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;

/**
 * Immutable, execution-ready description of a flow graph. The constructor
 * enforces STRUCTURAL invariants only (identity, referential integrity,
 * acyclicity); semantic rules that need the node catalog live in
 * {@see GraphValidator}. `$metadata` carries definition-level extras
 * (required run inputs, aggregate compensator, ...) round-tripped by the
 * serializer and ignored by structural checks.
 *
 * @api
 */
final class GraphDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $order;

    /**
     * @param  list<GraphNode>  $nodes
     * @param  list<Connection>  $connections
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $connections,
        public readonly array $metadata = [],
    ) {
        $violations = [];
        $byId = [];

        if ($this->nodes === []) {
            $violations[] = 'Graph must contain at least one node.';
        }

        foreach ($this->nodes as $node) {
            if (isset($byId[$node->id])) {
                $violations[] = "Duplicate node id [{$node->id}].";

                continue;
            }

            $byId[$node->id] = $node;
        }

        $seenWires = [];

        foreach ($this->connections as $wire) {
            foreach ([$wire->sourceNodeId, $wire->targetNodeId] as $endpoint) {
                if (! isset($byId[$endpoint])) {
                    $violations[] = "Connection [{$wire->identity()}] references unknown node [{$endpoint}].";
                }
            }

            if (isset($seenWires[$wire->identity()])) {
                $violations[] = "Duplicate connection [{$wire->identity()}].";
            }

            $seenWires[$wire->identity()] = true;
        }

        // Cycle detection runs only on a structurally sound graph: Kahn
        // over dangling/duplicate references would produce spurious
        // undefined-key failures. So when id/reference violations are
        // reported, a coexisting cycle surfaces on the next attempt.
        $order = $violations === [] ? $this->computeTopologicalOrder($byId) : [];

        if ($order === [] && $violations === [] && $this->nodes !== []) {
            $violations[] = 'Graph contains a cycle.';
        }

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }

        $this->order = $order;
    }

    public function node(string $id): ?GraphNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function nodeIds(): array
    {
        return array_map(static fn (GraphNode $node): string => $node->id, $this->nodes);
    }

    /**
     * Kahn order computed once at construction; the graph executor
     * (Macro C) consumes this for wave planning.
     *
     * @return list<string>
     */
    public function topologicalOrder(): array
    {
        return $this->order;
    }

    /**
     * @param  array<string, GraphNode>  $byId
     * @return list<string> empty when a cycle prevents completion
     */
    private function computeTopologicalOrder(array $byId): array
    {
        $inDegree = array_fill_keys(array_keys($byId), 0);
        $adjacency = [];

        foreach ($this->connections as $wire) {
            $adjacency[$wire->sourceNodeId][] = $wire->targetNodeId;
            $inDegree[$wire->targetNodeId]++;
        }

        $queue = [];

        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $order = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            foreach ($adjacency[$id] ?? [] as $next) {
                if (--$inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return count($order) === count($byId) ? $order : [];
    }
}
