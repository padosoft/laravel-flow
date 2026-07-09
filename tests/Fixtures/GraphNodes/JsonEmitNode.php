<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.jsonemit', category: 'testing')]
final class JsonEmitNode implements FlowNodeHandler
{
    #[Output(type: PortType::Json)]
    public array $data;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['data' => ['id' => $context->nodeId]]);
    }
}
