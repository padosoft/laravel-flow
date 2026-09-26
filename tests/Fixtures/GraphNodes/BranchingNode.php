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
 * Test node with two output ports, `yes` and `no`. The `take` input (set from
 * node config) picks the behaviour: `yes` / `no` activate that single port,
 * `both` activates both, `ghost` activates a port the node never declared, and
 * `plain` is an ordinary non-branching success that simply omits `no`. Every
 * branch result deliberately also returns an output for the INACTIVE port, so
 * the executor's stripping of inactive outputs is observable.
 */
#[FlowNode(type: 'test.branch', category: 'testing')]
final class BranchingNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: false)]
    public string $take = 'yes';

    #[Output(type: PortType::Json)]
    public array $yes;

    #[Output(type: PortType::Json)]
    public array $no;

    public function execute(NodeContext $context): NodeResult
    {
        $take = (string) ($context->inputs['take'] ?? 'yes');
        $outputs = ['yes' => ['from' => $context->nodeId], 'no' => ['from' => $context->nodeId]];

        return match ($take) {
            'yes' => NodeResult::branch($outputs, ['yes']),
            'no' => NodeResult::branch($outputs, ['no']),
            'both' => NodeResult::branch($outputs, ['yes', 'no']),
            'ghost' => NodeResult::branch($outputs, ['ghost']),
            default => NodeResult::success(['yes' => $outputs['yes']]),
        };
    }
}
