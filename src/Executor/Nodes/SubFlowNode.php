<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Built-in control node: runs a published flow as a nested child. `flow`
 * (+ optional `version`) selects the published child definition; `input` is the
 * child run's input. Emits `results` — a single-element ordered list holding the
 * child run's per-node output map (uniform with the fan-out nodes). Always
 * registered as `flow.subflow`. See {@see AbstractControlNode} for the sync
 * (inline) vs queued (suspend + spawn + join) execution split.
 *
 * @api
 */
#[FlowNode(type: 'flow.subflow', category: 'control')]
final class SubFlowNode extends AbstractControlNode
{
    #[Input(type: PortType::Text, required: false)]
    public string $flow = '';

    #[Input(type: PortType::Int, required: false)]
    public int $version = 0;

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $input = [];

    /** @var list<mixed> */
    #[Output(type: PortType::Json)]
    public array $results;

    protected function childInputs(NodeContext $context): array
    {
        $input = $context->inputs['input'] ?? [];

        return [is_array($input) ? $input : ['value' => $input]];
    }
}
