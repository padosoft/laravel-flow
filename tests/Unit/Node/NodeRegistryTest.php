<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\Node\Exceptions\DuplicateNodeTypeException;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use PHPUnit\Framework\TestCase;

final class NodeRegistryTest extends TestCase
{
    private NodeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new NodeRegistry(new NodeDefinitionFactory);
    }

    public function test_register_and_retrieve(): void
    {
        $definition = $this->registry->register(GreetNode::class);

        $this->assertSame('test.greet', $definition->type);
        $this->assertTrue($this->registry->has('test.greet'));
        $this->assertSame($definition, $this->registry->get('test.greet'));
        $this->assertSame(['test.greet'], array_keys($this->registry->all()));
    }

    public function test_duplicate_type_throws(): void
    {
        $this->registry->register(GreetNode::class);

        $this->expectException(DuplicateNodeTypeException::class);
        $this->registry->register(GreetNode::class);
    }

    public function test_non_handler_class_is_rejected(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/FlowNodeHandler/');
        $this->registry->register(FlowContext::class);
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(UnknownNodeTypeException::class);
        $this->registry->get('missing.type');
    }

    public function test_nonexistent_class_is_rejected_with_clear_message(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');
        $this->registry->register('App\\Does\\Not\\ExistHandler');
    }
}
