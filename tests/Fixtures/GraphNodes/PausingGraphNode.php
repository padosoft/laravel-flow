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

#[FlowNode(type: 'test.pause', category: 'testing')]
final class PausingGraphNode implements FlowNodeHandler
{
    #[Input(type: PortType::Json, required: false)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::paused();
    }
}
