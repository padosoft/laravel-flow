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

#[FlowNode(type: 'test.record', category: 'testing')]
final class InvocationRecordingNode implements FlowNodeHandler
{
    public static int $invocations = 0;

    #[Input(type: PortType::Text, required: true)]
    public string $required;

    #[Output(type: PortType::Text)]
    public string $echo;

    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations++;

        return NodeResult::success(['echo' => (string) ($context->inputs['required'] ?? '')]);
    }
}
