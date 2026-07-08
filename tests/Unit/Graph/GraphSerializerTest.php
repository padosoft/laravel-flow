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
}
