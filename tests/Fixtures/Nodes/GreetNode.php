<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.greet', category: 'testing')]
final class GreetNode
{
    #[Input(type: PortType::Text, required: true)]
    public string $name;

    #[Output(type: PortType::Text)]
    public string $greeting;
}
