<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\InvalidNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Deliberately invalid handler (duplicate input port keys): lives in its
 * own PSR-4 root so discovery-driven tests can prove the fail-fast boot
 * contract without polluting the valid Nodes fixture root.
 */
#[FlowNode(type: 'test.broken', category: 'testing')]
final class BrokenNode implements FlowNodeHandler
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
