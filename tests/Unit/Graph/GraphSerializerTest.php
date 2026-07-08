<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use PHPUnit\Framework\TestCase;

final class GraphSerializerTest extends TestCase
{
    private GraphSerializer $serializer;

    private GraphDefinition $graph;

    protected function setUp(): void
    {
        $this->serializer = new GraphSerializer;
        $this->graph = new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada'], ['x' => 1, 'y' => 2]), new GraphNode('u', 'test.upper')],
            [new Connection('g', 'greeting', 'u', 'text')],
            ['required_inputs' => ['name']],
        );
    }

    public function test_round_trip_preserves_the_graph(): void
    {
        $rebuilt = $this->serializer->fromArray($this->serializer->toArray($this->graph));

        $this->assertSame($this->serializer->checksum($this->graph), $this->serializer->checksum($rebuilt));
        $this->assertSame(['g', 'u'], $rebuilt->nodeIds());
        $this->assertSame(['required_inputs' => ['name']], $rebuilt->metadata);
        $this->assertSame(['x' => 1, 'y' => 2], $rebuilt->node('g')?->position);
    }

    public function test_envelope_shape(): void
    {
        $array = $this->serializer->toArray($this->graph);

        $this->assertSame(GraphSerializer::SCHEMA_VERSION, $array['schema_version']);
        $this->assertSame(GraphSerializer::KIND, $array['kind']);
        $this->assertCount(2, $array['nodes']);
        $this->assertSame('g', $array['nodes'][0]['id']);
        $this->assertSame('greeting', $array['connections'][0]['sourcePortKey']);
    }

    public function test_checksum_is_stable_across_key_order(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $shuffled = $array;
        $shuffled['nodes'][0] = array_reverse($shuffled['nodes'][0], true);
        krsort($shuffled);

        $this->assertSame(
            $this->serializer->checksum($this->serializer->fromArray($array)),
            $this->serializer->checksum($this->serializer->fromArray($shuffled)),
        );
    }

    public function test_unknown_schema_version_is_rejected(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $array['schema_version'] = 99;

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/schema_version/i');
        $this->serializer->fromArray($array);
    }

    public function test_wrong_kind_is_rejected(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $array['kind'] = 'other';

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/kind/i');
        $this->serializer->fromArray($array);
    }

    public function test_json_round_trip(): void
    {
        $json = $this->serializer->toJson($this->graph);
        $rebuilt = $this->serializer->fromJson($json);

        $this->assertSame($this->serializer->checksum($this->graph), $this->serializer->checksum($rebuilt));
    }

    public function test_from_array_aggregates_all_field_level_violations(): void
    {
        $payload = [
            'schema_version' => GraphSerializer::SCHEMA_VERSION,
            'kind' => GraphSerializer::KIND,
            'metadata' => [],
            'nodes' => [
                ['id' => 'bad-position', 'type' => 'test.greet', 'config' => [], 'position' => ['x' => '10', 'y' => 2]],
                ['id' => '', 'type' => 'test.greet'],
            ],
            'connections' => [
                ['sourceNodeId' => 'bad-position', 'sourcePortKey' => 'out', 'targetNodeId' => 'bad-position', 'targetPortKey' => 'in'],
            ],
        ];

        try {
            $this->serializer->fromArray($payload);
            $this->fail('Expected InvalidGraphException to be thrown.');
        } catch (InvalidGraphException $e) {
            $violations = $e->violations();
            $joined = implode(' | ', $violations);

            $this->assertGreaterThanOrEqual(3, count($violations));
            $this->assertStringContainsString('Node at index 0', $joined);
            $this->assertStringContainsString('Node at index 1', $joined);
            $this->assertStringContainsString('Connection at index 0', $joined);
        }
    }

    public function test_from_array_rejects_non_array_connections_envelope_field(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $array['connections'] = 'corrupted';

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/envelope field \[connections\] must be an array/i');
        $this->serializer->fromArray($array);
    }

    public function test_checksum_is_stable_when_node_config_key_order_differs_without_from_array(): void
    {
        $graphA = new GraphDefinition(
            [new GraphNode('n', 'test.greet', ['b' => 2, 'a' => 1, 'nested' => ['y' => 2, 'x' => 1]])],
            [],
        );

        $graphB = new GraphDefinition(
            [new GraphNode('n', 'test.greet', ['a' => 1, 'b' => 2, 'nested' => ['x' => 1, 'y' => 2]])],
            [],
        );

        $this->assertSame($this->serializer->checksum($graphA), $this->serializer->checksum($graphB));
    }

    public function test_from_array_rejects_non_array_node_config(): void
    {
        $payload = [
            'schema_version' => GraphSerializer::SCHEMA_VERSION,
            'kind' => GraphSerializer::KIND,
            'metadata' => [],
            'nodes' => [
                ['id' => 'n1', 'type' => 'test.greet', 'config' => 'this-should-be-an-array-but-is-a-string'],
            ],
            'connections' => [],
        ];

        try {
            $this->serializer->fromArray($payload);
            $this->fail('Expected InvalidGraphException to be thrown.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('Node at index 0', implode(' | ', $e->violations()));
            $this->assertStringContainsString('[config] must be an array', implode(' | ', $e->violations()));
        }
    }

    public function test_from_array_rejects_non_array_non_null_node_position(): void
    {
        $payload = [
            'schema_version' => GraphSerializer::SCHEMA_VERSION,
            'kind' => GraphSerializer::KIND,
            'metadata' => [],
            'nodes' => [
                ['id' => 'n1', 'type' => 'test.greet', 'position' => 'not-an-array-or-null'],
            ],
            'connections' => [],
        ];

        try {
            $this->serializer->fromArray($payload);
            $this->fail('Expected InvalidGraphException to be thrown.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('Node at index 0', implode(' | ', $e->violations()));
            $this->assertStringContainsString('[position] must be an array or null', implode(' | ', $e->violations()));
        }
    }

    public function test_checksum_is_stable_across_node_and_connection_list_order(): void
    {
        $graphA = new GraphDefinition(
            [new GraphNode('a', 'test.greet'), new GraphNode('b', 'test.greet'), new GraphNode('c', 'test.greet')],
            [new Connection('a', 'out', 'b', 'in'), new Connection('b', 'out', 'c', 'in')],
        );

        $graphB = new GraphDefinition(
            [new GraphNode('c', 'test.greet'), new GraphNode('a', 'test.greet'), new GraphNode('b', 'test.greet')],
            [new Connection('b', 'out', 'c', 'in'), new Connection('a', 'out', 'b', 'in')],
        );

        $this->assertSame($this->serializer->checksum($graphA), $this->serializer->checksum($graphB));
    }

    public function test_explicit_null_fields_are_treated_as_absent(): void
    {
        // Common JSON producers emit null for empty maps/lists.
        $graph = $this->serializer->fromArray([
            'schema_version' => GraphSerializer::SCHEMA_VERSION,
            'kind' => GraphSerializer::KIND,
            'metadata' => null,
            'nodes' => [
                ['id' => 'n1', 'type' => 'test.greet', 'config' => null, 'position' => null],
            ],
            'connections' => null,
        ]);

        $this->assertSame([], $graph->node('n1')?->config);
        $this->assertNull($graph->node('n1')?->position);
        $this->assertSame([], $graph->metadata);
        $this->assertSame([], $graph->connections);
    }
}
