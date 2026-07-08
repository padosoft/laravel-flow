<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\Exceptions\DuplicateNodeTypeException;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeCatalog;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeDiscovery;
use Padosoft\LaravelFlow\Node\NodeInputHydrator;
use Padosoft\LaravelFlow\Node\NodeInputValidator;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortDefinition;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class NodeApiContractTest extends TestCase
{
    public function test_port_type_cases_are_pinned(): void
    {
        $this->assertSame(
            ['text', 'int', 'float', 'bool', 'json', 'any'],
            array_map(static fn (PortType $c): string => $c->value, PortType::cases()),
        );
        $this->assertTrue((new ReflectionClass(PortType::class))->hasMethod('accepts'));
    }

    public function test_node_api_classes_are_annotated_api(): void
    {
        $classes = [
            PortType::class,
            PortDefinition::class,
            FlowNode::class,
            Input::class,
            Output::class,
            NodeDefinition::class,
            NodeDefinitionFactory::class,
            InvalidNodeDefinitionException::class,
            NodeInputValidator::class,
            NodeInputHydrator::class,
            NodeInputValidationException::class,
            FlowNodeHandler::class,
            NodeContext::class,
            NodeResult::class,
            NodeRegistry::class,
            DuplicateNodeTypeException::class,
            UnknownNodeTypeException::class,
            NodeDiscovery::class,
            NodeCatalog::class,
            LegacyStepNodeAdapter::class,
        ];

        foreach ($classes as $class) {
            $doc = (string) (new ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class);
            $this->assertStringNotContainsString('@internal', $doc, $class);
        }
    }

    public function test_node_result_factories_are_pinned(): void
    {
        foreach (['success', 'failed', 'dryRunSkipped', 'paused'] as $factory) {
            $this->assertTrue((new ReflectionClass(NodeResult::class))->getMethod($factory)->isStatic(), $factory);
        }
    }

    public function test_node_catalog_schema_version_is_pinned(): void
    {
        $this->assertSame(1, NodeCatalog::SCHEMA_VERSION);
    }

    public function test_flow_node_handler_signature_is_pinned(): void
    {
        $method = (new ReflectionClass(FlowNodeHandler::class))->getMethod('execute');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(NodeResult::class, $returnType->getName());

        $parameterType = $method->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $parameterType);
        $this->assertSame(NodeContext::class, $parameterType->getName());
    }

    public function test_registry_and_catalog_public_methods_are_pinned(): void
    {
        foreach (['register', 'registerMany', 'has', 'get', 'all'] as $method) {
            $this->assertTrue((new ReflectionClass(NodeRegistry::class))->hasMethod($method), $method);
        }

        foreach (['toArray', 'toJson'] as $method) {
            $this->assertTrue((new ReflectionClass(NodeCatalog::class))->hasMethod($method), $method);
        }

        $this->assertSame(1, NodeCatalog::SCHEMA_VERSION);
    }
}
