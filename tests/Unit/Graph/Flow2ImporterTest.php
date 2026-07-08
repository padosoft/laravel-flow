<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\Flow2Importer;
use PHPUnit\Framework\TestCase;

final class Flow2ImporterTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/flow2/descrivi-immagine.json');
    }

    public function test_imports_the_realistic_envelope_fixture_end_to_end(): void
    {
        $graph = (new Flow2Importer)->import($this->fixture());

        $this->assertSame(['flow-input-1', 'image-rater-1', 'output-1'], $graph->nodeIds());

        $input = $graph->node('flow-input-1');
        $this->assertNotNull($input);
        $this->assertSame('flow-input', $input->type);
        $this->assertSame(['image_url'], $input->config['input_keys']);
        $this->assertSame(['x' => 80, 'y' => 160], $input->position);

        $rater = $graph->node('image-rater-1');
        $this->assertNotNull($rater);
        $this->assertSame('image_rater', $rater->type);
        $this->assertSame('gpt-4o-mini', $rater->config['model']);
        $this->assertSame('object', $rater->config['param_schema']['type']);

        $output = $graph->node('output-1');
        $this->assertNotNull($output);
        $this->assertSame('output', $output->type);

        $this->assertCount(2, $graph->connections);
        $first = $graph->connections[0];
        $this->assertSame('flow-input-1', $first->sourceNodeId);
        $this->assertSame('image_url', $first->sourcePortKey);
        $this->assertSame('image-rater-1', $first->targetNodeId);
        $this->assertSame('image_url', $first->targetPortKey);
    }

    public function test_drops_the_source_apps_own_connection_id(): void
    {
        $graph = (new Flow2Importer)->import($this->fixture());

        // Connection is a plain 4-field VO: if the source `id` field had
        // leaked through construction would have failed with an unknown
        // named-argument error, so reaching this assertion already proves
        // it was dropped. Assert on the derived identity instead.
        $this->assertSame('image-rater-1.result>output-1.result', $graph->connections[1]->identity());
    }

    public function test_accepts_a_bare_config_without_an_envelope_wrapper(): void
    {
        $json = json_encode([
            'nodes' => [
                ['id' => 'a', 'serviceType' => 'flow-input', 'data' => []],
                ['id' => 'b', 'serviceType' => 'output', 'data' => []],
            ],
            'connections' => [
                ['sourceNodeId' => 'a', 'sourcePortKey' => 'out', 'targetNodeId' => 'b', 'targetPortKey' => 'in'],
            ],
        ], JSON_THROW_ON_ERROR);

        $graph = (new Flow2Importer)->import($json);

        $this->assertSame(['a', 'b'], $graph->nodeIds());
        $this->assertCount(1, $graph->connections);
    }

    public function test_rejects_an_envelope_with_a_kind_other_than_flow2(): void
    {
        $json = json_encode([
            'version' => 1,
            'kind' => 'not-flow2',
            'config' => [
                'nodes' => [['id' => 'a', 'serviceType' => 'flow-input', 'data' => []]],
                'connections' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessage("Unsupported or missing Flow-v2 envelope kind; expected 'flow2'.");

        (new Flow2Importer)->import($json);
    }

    public function test_rejects_an_envelope_missing_the_kind_key(): void
    {
        $json = json_encode([
            'version' => 1,
            'config' => [
                'nodes' => [['id' => 'a', 'serviceType' => 'flow-input', 'data' => []]],
                'connections' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidGraphException::class);

        (new Flow2Importer)->import($json);
    }

    public function test_aggregates_malformed_node_and_connection_violations(): void
    {
        $json = json_encode([
            'nodes' => [
                ['id' => 'a', 'serviceType' => 'flow-input', 'data' => []],
                ['id' => 'missing-service-type'],
            ],
            'connections' => [
                ['sourceNodeId' => 'a'],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            (new Flow2Importer)->import($json);
            $this->fail('Expected InvalidGraphException.');
        } catch (InvalidGraphException $e) {
            $this->assertCount(2, $e->violations());
            $this->assertStringContainsString('Malformed Flow-v2 node entry at index 1.', $e->violations()[0]);
            $this->assertStringContainsString('Malformed Flow-v2 connection entry at index 0.', $e->violations()[1]);
        }
    }

    public function test_structural_violations_from_the_graph_definition_vo_surface(): void
    {
        $json = json_encode([
            'nodes' => [
                ['id' => 'a', 'serviceType' => 'flow-input', 'data' => []],
            ],
            'connections' => [
                ['sourceNodeId' => 'a', 'sourcePortKey' => 'out', 'targetNodeId' => 'unknown', 'targetPortKey' => 'in'],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            (new Flow2Importer)->import($json);
            $this->fail('Expected InvalidGraphException.');
        } catch (InvalidGraphException $e) {
            $this->assertStringContainsString('references unknown node [unknown]', $e->getMessage());
        }
    }

    public function test_rejects_malformed_json(): void
    {
        $this->expectException(InvalidGraphException::class);

        (new Flow2Importer)->import('{not-json');
    }

    public function test_rejects_a_json_list_payload(): void
    {
        $this->expectException(InvalidGraphException::class);

        (new Flow2Importer)->import('[1,2,3]');
    }
}
