<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.upper', category: 'testing')]
final class UpperNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true)]
    public string $text;

    #[Output(type: PortType::Text)]
    public string $upper;

    public function execute(NodeContext $context): NodeResult
    {
        $text = $context->inputs['text'];
        assert(is_string($text));

        return NodeResult::success(['upper' => mb_strtoupper($text)]);
    }
}
