<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GraphApiContractTest extends TestCase
{
    public function test_graph_api_classes_are_annotated_api(): void
    {
        $classes = [
            GraphNode::class,
            Connection::class,
            GraphDefinition::class,
            GraphValidator::class,
            InvalidGraphException::class,
            GraphSerializer::class,
        ];

        foreach ($classes as $class) {
            $doc = (string) (new ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class);
            $this->assertStringNotContainsString('@internal', $doc, $class);
        }
    }

    public function test_graph_definition_exposes_topological_order(): void
    {
        $this->assertTrue((new ReflectionClass(GraphDefinition::class))->hasMethod('topologicalOrder'));
    }

    public function test_graph_schema_constants_are_pinned(): void
    {
        $this->assertSame(1, GraphSerializer::SCHEMA_VERSION);
        $this->assertSame('laravel-flow', GraphSerializer::KIND);
    }
}
