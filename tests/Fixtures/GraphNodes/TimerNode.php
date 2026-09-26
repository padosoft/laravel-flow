<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Test delay node: pauses until `seconds` from now (NodeResult::pausedUntil()),
 * then hands `in` through on `out`. Counts how often the handler itself ran, so
 * a resume that wrongly re-executed it is observable.
 */
#[FlowNode(type: 'test.timer', category: 'testing')]
final class TimerNode implements FlowNodeHandler
{
    public static int $executions = 0;

    #[Input(type: PortType::Int, required: false)]
    public int $seconds = 0;

    #[Input(type: PortType::Json, required: false)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        self::$executions++;

        return NodeResult::pausedUntil(
            Date::now()->addSeconds((int) ($context->inputs['seconds'] ?? 0)),
            ['out' => $context->inputs['in'] ?? ['through' => true]],
        );
    }
}
