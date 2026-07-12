<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Built-in fan-out control node: runs the published `flow` once per entry of the
 * `items` list — one child run per item (an array item is the child's input map,
 * a scalar item becomes `['value' => item]`), capped by `maxConcurrency`.
 * Emits `results`: the ordered per-child output list. `maxConcurrency` is REAL
 * concurrency on the queued executor and a sequential batch size on the
 * synchronous one (see {@see AbstractControlNode}). Registered as `flow.foreach`.
 *
 * @api
 */
#[FlowNode(type: 'flow.foreach', category: 'control')]
final class ForEachNode extends AbstractControlNode
{
    #[Input(type: PortType::Text, required: false)]
    public string $flow = '';

    #[Input(type: PortType::Int, required: false)]
    public int $maxConcurrency = 1;

    /** @var list<mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $items = [];

    /** @var list<mixed> */
    #[Output(type: PortType::Json)]
    public array $results;
}
