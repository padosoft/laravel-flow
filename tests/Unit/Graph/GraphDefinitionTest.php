<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use PHPUnit\Framework\TestCase;

final class GraphDefinitionTest extends TestCase
{
    public function test_diamond_graph_is_accepted_with_topological_order(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('a', 't'), new GraphNode('b', 't'), new GraphNode('c', 't'), new GraphNode('d', 't')],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
                new Connection('b', 'out', 'd', 'in'),
                new Connection('c', 'out', 'd', 'in'),
            ],
        );

        $order = $graph->topologicalOrder();

        $this->assertSame('a', $order[0]);
        $this->assertSame('d', $order[3]);
        $this->assertEqualsCanonicalizing(['b', 'c'], [$order[1], $order[2]]);
        $this->assertSame(['a', 'b', 'c', 'd'], $graph->nodeIds());
        $this->assertNotNull($graph->node('b'));
        $this->assertNull($graph->node('zz'));
    }

    public function test_empty_graph_is_rejected(): void
    {
        $this->expectException(InvalidGraphException::class);

        new GraphDefinition([], []);
    }

    public function test_structural_violations_are_collected_together(): void
    {
        try {
            new GraphDefinition(
                [new GraphNode('a', 't'), new GraphNode('a', 't'), new GraphNode('b', 't')],
                [
                    new Connection('a', 'out', 'ghost', 'in'),
                    new Connection('a', 'out', 'b', 'in'),
                    new Connection('a', 'out', 'b', 'in'),
                ],
            );
            $this->fail('Expected InvalidGraphException');
        } catch (InvalidGraphException $e) {
            $joined = implode(' | ', $e->violations());
            $this->assertStringContainsString('Duplicate node id [a]', $joined);
            $this->assertStringContainsString('unknown node [ghost]', $joined);
            $this->assertStringContainsString('Duplicate connection [a.out>b.in]', $joined);
        }
    }

    public function test_cycle_is_rejected(): void
    {
        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        new GraphDefinition(
            [new GraphNode('a', 't'), new GraphNode('b', 't')],
            [new Connection('a', 'out', 'b', 'in'), new Connection('b', 'out', 'a', 'in')],
        );
    }
}
