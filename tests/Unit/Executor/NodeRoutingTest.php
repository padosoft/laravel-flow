<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Padosoft\LaravelFlow\Executor\NodeRouting;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use PHPUnit\Framework\TestCase;

final class NodeRoutingTest extends TestCase
{
    public function test_connections_into_orders_by_source_topological_index(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('a', 'test'), new GraphNode('b', 'test'), new GraphNode('c', 'test')],
            [
                new Connection('b', 'out', 'c', 'items'),
                new Connection('a', 'out', 'c', 'items'),
            ],
        );

        $wires = NodeRouting::connectionsInto($graph, 'c', ['a' => 0, 'b' => 1, 'c' => 2]);

        $this->assertSame(['a', 'b'], array_map(static fn (Connection $c): string => $c->sourceNodeId, $wires));
    }

    public function test_connections_sharing_the_same_source_index_break_the_tie_on_connection_identity_not_array_position(): void
    {
        // Two output ports of the SAME source node ('a') both wired into the
        // same fan-in target: same source topological index, so the primary
        // sort key alone cannot order them. Declared in REVERSE identity order
        // here — the identity tie-break must still put 'first' before 'second'
        // regardless of how the connections happened to be serialized.
        $graph = new GraphDefinition(
            [new GraphNode('a', 'test'), new GraphNode('c', 'test')],
            [
                new Connection('a', 'second', 'c', 'items'),
                new Connection('a', 'first', 'c', 'items'),
            ],
        );

        $wires = NodeRouting::connectionsInto($graph, 'c', ['a' => 0, 'c' => 1]);

        $this->assertSame(['first', 'second'], array_map(static fn (Connection $c): string => $c->sourcePortKey, $wires));
    }

    public function test_the_tie_break_order_is_stable_regardless_of_declaration_order(): void
    {
        // Build the SAME semantic graph (same connections, different array
        // order) two ways and assert connectionsInto() produces the IDENTICAL
        // wire order both times — proving the result depends on the
        // connections' own identity, not their incidental serialized position.
        $forward = new GraphDefinition(
            [new GraphNode('a', 'test'), new GraphNode('c', 'test')],
            [new Connection('a', 'x', 'c', 'items'), new Connection('a', 'y', 'c', 'items')],
        );
        $reversed = new GraphDefinition(
            [new GraphNode('a', 'test'), new GraphNode('c', 'test')],
            [new Connection('a', 'y', 'c', 'items'), new Connection('a', 'x', 'c', 'items')],
        );

        $sequenceOf = ['a' => 0, 'c' => 1];
        $forwardOrder = array_map(static fn (Connection $c): string => $c->identity(), NodeRouting::connectionsInto($forward, 'c', $sequenceOf));
        $reversedOrder = array_map(static fn (Connection $c): string => $c->identity(), NodeRouting::connectionsInto($reversed, 'c', $sequenceOf));

        $this->assertSame($forwardOrder, $reversedOrder);
    }
}
