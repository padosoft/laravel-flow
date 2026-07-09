<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\Nodes;

use Padosoft\LaravelFlow\Executor\Nodes\MergeNode;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\TestCase;

final class MergeNodeTest extends TestCase
{
    public function test_merge_coalesces_upstream_outputs_into_list(): void
    {
        $context = new NodeContext('run-1', 'flow.demo', 'merge', ['items' => [['a' => 1], ['b' => 2]]]);

        $result = (new MergeNode)->execute($context);

        $this->assertTrue($result->success);
        $this->assertSame(['merged' => [['a' => 1], ['b' => 2]]], $result->outputs);
    }

    public function test_merge_with_no_items_emits_empty_list(): void
    {
        $result = (new MergeNode)->execute(new NodeContext('run-1', 'flow.demo', 'merge', []));

        $this->assertTrue($result->success);
        $this->assertSame(['merged' => []], $result->outputs);
    }

    public function test_merge_is_registered_as_builtin(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('flow.merge'));

        $definition = $registry->get('flow.merge');
        $this->assertSame(MergeNode::class, $definition->handlerClass);
        $items = $definition->input('items');
        $this->assertNotNull($items);
        $this->assertTrue($items->multiple);
    }
}
