<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\CollidingNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * Half of a deliberate type collision (see FirstCollidingNode).
 */
#[FlowNode(type: 'test.collide', category: 'testing')]
final class SecondCollidingNode implements FlowNodeHandler
{
    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success();
    }
}
