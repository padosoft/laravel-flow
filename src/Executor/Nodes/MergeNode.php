<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Built-in fan-in primitive: accepts N upstream outputs on one `multiple`
 * input port and emits them as a single coalesced list. The explicit
 * counterpart to fan-out; always registered as `flow.merge`.
 *
 * @api
 */
#[FlowNode(type: 'flow.merge', category: 'control')]
final class MergeNode implements FlowNodeHandler
{
    /** @var list<mixed> */
    #[Input(type: PortType::Json, required: false, multiple: true)]
    public array $items = [];

    /** @var list<mixed> */
    #[Output(type: PortType::Json)]
    public array $merged;

    public function execute(NodeContext $context): NodeResult
    {
        /** @var array<int, mixed> $items */
        $items = $context->inputs['items'] ?? [];

        return NodeResult::success(['merged' => array_values($items)]);
    }
}
