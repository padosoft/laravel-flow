<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\CompensatableNode;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Saga fixture: succeeds, and records every compensate() call into a SHARED
 * static order log (`$log`, also appended to by the aggregate-compensator
 * fixture) so tests can assert reverse-topological compensation order. The
 * `in` port is `multiple` so the same fixture can serve as a diamond join.
 */
#[FlowNode(type: 'test.saga.comp', category: 'testing')]
final class CompensatableRecordingNode implements CompensatableNode, FlowNodeHandler
{
    /** @var list<string> compensation order: node ids and '@aggregate' */
    public static array $log = [];

    /** @var array<string, array<string, mixed>> node id => inputs seen by compensate() */
    public static array $contexts = [];

    /** @var array<string, bool> node id => the queued flag compensate() saw */
    public static array $queuedFlags = [];

    public static function reset(): void
    {
        self::$log = [];
        self::$contexts = [];
        self::$queuedFlags = [];
    }

    #[Input(type: PortType::Json, required: false, multiple: true)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['out' => ['produced_by' => $context->nodeId]]);
    }

    public function compensate(NodeContext $context): void
    {
        self::$log[] = $context->nodeId;
        self::$contexts[$context->nodeId] = $context->inputs;
        self::$queuedFlags[$context->nodeId] = $context->queued;
    }
}
