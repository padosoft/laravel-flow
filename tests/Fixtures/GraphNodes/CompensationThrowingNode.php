<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\CompensatableNode;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use RuntimeException;

/**
 * Saga fixture: EXECUTES successfully but THROWS on compensate(), so tests can
 * assert a failing compensator is recorded without aborting the remaining
 * rollback (and that the run is NOT marked compensated).
 */
#[FlowNode(type: 'test.saga.compthrow', category: 'testing')]
final class CompensationThrowingNode implements CompensatableNode, FlowNodeHandler
{
    #[Input(type: PortType::Json, required: false, multiple: true)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['out' => ['produced_by' => $context->nodeId]]);
    }

    public function compensate(NodeContext $context): void
    {
        throw new RuntimeException('compensation failed on purpose');
    }
}
