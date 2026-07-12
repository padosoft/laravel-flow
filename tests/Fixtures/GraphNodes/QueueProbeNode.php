<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Records how many times each node id was executed, so the queued-coordinator
 * tests can assert a node ran exactly once (no double execution) even under
 * duplicate job delivery. Works as a root (optional input) or downstream node.
 */
#[FlowNode(type: 'test.probe', category: 'testing')]
final class QueueProbeNode implements FlowNodeHandler
{
    /**
     * @var array<string, int>
     */
    public static array $invocations = [];

    #[Input(type: PortType::Json, required: false)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations[$context->nodeId] = (self::$invocations[$context->nodeId] ?? 0) + 1;

        return NodeResult::success(['out' => ['id' => $context->nodeId]]);
    }

    public static function reset(): void
    {
        self::$invocations = [];
    }

    public static function count(string $nodeId): int
    {
        return self::$invocations[$nodeId] ?? 0;
    }
}
