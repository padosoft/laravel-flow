<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Illuminate\Container\Container;
use Padosoft\LaravelFlow\Executor\NodeResolver;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use PHPUnit\Framework\TestCase;

final class NodeResolverTest extends TestCase
{
    private function resolver(): NodeResolver
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->register(GreetNode::class);

        return new NodeResolver($registry, new Container);
    }

    public function test_resolves_registered_node_from_container(): void
    {
        $resolved = $this->resolver()->resolve(new GraphNode('g', 'test.greet', ['name' => 'Ada']));

        $this->assertInstanceOf(GreetNode::class, $resolved->handler);
        $this->assertSame('test.greet', $resolved->definition->type);
        $this->assertSame(GreetNode::class, $resolved->definition->handlerClass);
    }

    public function test_resolves_legacy_step_via_adapter(): void
    {
        $node = new GraphNode('s', FlowDefinition::LEGACY_NODE_TYPE, ['handler' => AlwaysSucceedsHandler::class]);

        $resolved = $this->resolver()->resolve($node);

        $this->assertInstanceOf(LegacyStepNodeAdapter::class, $resolved->handler);
        $this->assertSame(FlowDefinition::LEGACY_NODE_TYPE, $resolved->definition->type);
        $this->assertSame(AlwaysSucceedsHandler::class, $resolved->definition->handlerClass);
        $this->assertSame(PortType::Json, $resolved->definition->input('input')->type);
        $this->assertSame(PortType::Json, $resolved->definition->output('output')->type);
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(UnknownNodeTypeException::class);
        $this->resolver()->resolve(new GraphNode('x', 'missing.type'));
    }

    public function test_legacy_node_missing_handler_config_throws(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessage("missing a string 'handler'");
        $this->resolver()->resolve(new GraphNode('s', FlowDefinition::LEGACY_NODE_TYPE));
    }
}
