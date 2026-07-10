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
 * Child-flow fixture: doubles the `value` of its seeded run input. Used as the
 * published child flow for the sub-flow / fan-out control-node tests.
 */
#[FlowNode(type: 'test.double', category: 'testing')]
final class DoubleNode implements FlowNodeHandler
{
    public static int $invocations = 0;

    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $input = [];

    #[Output(type: PortType::Int)]
    public int $doubled;

    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations++;

        /** @var array<string, mixed> $input */
        $input = $context->inputs['input'] ?? [];
        $value = is_numeric($input['value'] ?? null) ? (int) $input['value'] : 0;

        return NodeResult::success(['doubled' => $value * 2]);
    }
}
