<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\ShadowedNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares the same type as the GreetNode fixture but is deliberately
 * malformed (duplicate input keys): proves that config-over-discovery
 * precedence skips shadowed classes BEFORE validating them, so a broken
 * override target can never fail application boot.
 */
#[FlowNode(type: 'test.greet', category: 'testing')]
final class ShadowedBrokenGreetNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, key: 'same', required: true)]
    public string $a;

    #[Input(type: PortType::Text, key: 'same', required: true)]
    public string $b;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success();
    }
}
