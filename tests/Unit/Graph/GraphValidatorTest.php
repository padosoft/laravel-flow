<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\CountNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

final class GraphValidatorTest extends TestCase
{
    private GraphValidator $validator;

    protected function setUp(): void
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->registerMany([GreetNode::class, UpperNode::class, CountNode::class]);
        $this->validator = new GraphValidator($registry);
    }

    public function test_valid_wired_graph_passes(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('u', 'test.upper')],
            [new Connection('g', 'greeting', 'u', 'text')],
        );

        $this->validator->validate($graph);
        $this->addToAssertionCount(1);
    }

    public function test_unknown_node_type_violates(): void
    {
        $graph = new GraphDefinition([new GraphNode('x', 'missing.type', ['name' => 'v'])], []);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/unknown node type \[missing\.type\]/i');
        $this->validator->validate($graph);
    }

    public function test_unknown_ports_violate(): void
    {
        try {
            $this->validator->validate(new GraphDefinition(
                [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('u', 'test.upper')],
                [new Connection('g', 'nope', 'u', 'wrong')],
            ));
            $this->fail('Expected InvalidGraphException');
        } catch (InvalidGraphException $e) {
            $joined = implode(' | ', $e->violations());
            $this->assertStringContainsString('output port [nope]', $joined);
            $this->assertStringContainsString('input port [wrong]', $joined);
        }
    }

    public function test_unwired_required_input_without_config_violates(): void
    {
        $graph = new GraphDefinition([new GraphNode('g', 'test.greet')], []);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/required input \[name\].*unwired/i');
        $this->validator->validate($graph);
    }

    public function test_port_type_incompatibility_violates(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('c', 'test.count')],
            [new Connection('g', 'greeting', 'c', 'seed')],
        );

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/\[text\].*cannot feed.*\[int\]/i');
        $this->validator->validate($graph);
    }

    public function test_config_literal_with_wrong_type_violates(): void
    {
        $graph = new GraphDefinition([new GraphNode('c', 'test.count', ['seed' => 'abc'])], []);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/config value for input \[seed\] on node \[c\] must be of type \[int\]/i');
        $this->validator->validate($graph);
    }

    public function test_null_config_literal_on_required_input_violates(): void
    {
        $graph = new GraphDefinition([new GraphNode('c', 'test.count', ['seed' => null])], []);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/config value for required input \[seed\] on node \[c\] must not be null/i');
        $this->validator->validate($graph);
    }

    public function test_valid_config_literal_passes(): void
    {
        $graph = new GraphDefinition([new GraphNode('c', 'test.count', ['seed' => 5])], []);

        $this->validator->validate($graph);
        $this->addToAssertionCount(1);
    }

    public function test_fan_in_on_a_single_input_port_violates(): void
    {
        // Two sources into one input port would give the executor ambiguous
        // last-write-wins semantics: rejected until explicit merge nodes.
        $graph = new GraphDefinition(
            [
                new GraphNode('g1', 'test.greet', ['name' => 'Ada']),
                new GraphNode('g2', 'test.greet', ['name' => 'Bob']),
                new GraphNode('u', 'test.upper'),
            ],
            [
                new Connection('g1', 'greeting', 'u', 'text'),
                new Connection('g2', 'greeting', 'u', 'text'),
            ],
        );

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/input port \[text\] on node \[u\] is wired from multiple sources/i');
        $this->validator->validate($graph);
    }
}
