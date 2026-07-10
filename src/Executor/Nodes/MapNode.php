<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Built-in fan-out control node: maps the published `flow` over the `items`
 * list — one child run per item, capped by `maxConcurrency` — and emits
 * `results`, the ordered list of each child run's output. Mechanically the same
 * fan-out + ordered join as {@see ForEachNode}; `flow.map` names the intent of
 * transforming a list into a list of per-item outputs. `maxConcurrency` is REAL
 * concurrency on the queued executor and a sequential batch size on the
 * synchronous one (see {@see AbstractControlNode}). Registered as `flow.map`.
 *
 * @api
 */
#[FlowNode(type: 'flow.map', category: 'control')]
final class MapNode extends AbstractControlNode
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
