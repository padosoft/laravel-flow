<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use DateTimeImmutable;
use Illuminate\Container\Container;
use Padosoft\LaravelFlow\Executor\InputRouter;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\NodeResolver;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\InvocationRecordingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\JsonEmitNode;
use PHPUnit\Framework\TestCase;

final class NodeExecutorTest extends TestCase
{
    private function executor(): NodeExecutor
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->registerMany([JsonEmitNode::class, InvocationRecordingNode::class]);

        return new NodeExecutor(
            new NodeResolver($registry, new Container),
            new InputRouter,
            static fn (): DateTimeImmutable => new DateTimeImmutable,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        InvocationRecordingNode::$invocations = 0;
    }

    public function test_executes_a_valid_node_and_returns_outputs(): void
    {
        $execution = $this->executor()->execute(
            'run-1',
            'flow.demo',
            new GraphNode('a', 'test.jsonemit'),
            [],
            [],
            false,
            0,
            null, // no persistence
        );

        $this->assertSame(NodeState::Succeeded, $execution->state);
        $this->assertSame(['data' => ['id' => 'a']], $execution->outputs);
    }

    public function test_invalid_input_short_circuits_without_running_handler(): void
    {
        $execution = $this->executor()->execute(
            'run-1',
            'flow.demo',
            new GraphNode('r', 'test.record'), // required input unwired
            [],
            [],
            false,
            0,
            null,
        );

        $this->assertSame(NodeState::InvalidInput, $execution->state);
        $this->assertSame(0, InvocationRecordingNode::$invocations);
        $this->assertNotNull($execution->error);
    }
}
