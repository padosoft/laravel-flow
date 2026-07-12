<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Executor\Attributes\Cost;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Planner fixture: declares a `#[Cost]` estimate for the DAG dry-run planner.
 * The `in` port is `multiple` so the same fixture can serve as a diamond join.
 */
#[FlowNode(type: 'test.costed', category: 'testing')]
final class CostedNode implements FlowNodeHandler
{
    #[Input(type: PortType::Json, required: false, multiple: true)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    #[Cost(estimate: ['tokens' => 100, 'cents' => 2])]
    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['out' => ['produced_by' => $context->nodeId]]);
    }
}
