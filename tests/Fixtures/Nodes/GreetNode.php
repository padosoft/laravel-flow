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

#[FlowNode(type: 'test.greet', category: 'testing')]
final class GreetNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true)]
    public string $name;

    #[Output(type: PortType::Text)]
    public string $greeting;

    public function execute(NodeContext $context): NodeResult
    {
        $name = $context->inputs['name'];
        assert(is_string($name));

        return NodeResult::success(['greeting' => 'Hello '.$name]);
    }
}
