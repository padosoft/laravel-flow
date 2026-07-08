<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use InvalidArgumentException;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.count', category: 'testing')]
final class CountNode implements FlowNodeHandler
{
    #[Input(type: PortType::Int, required: true)]
    public int $seed;

    #[Output(type: PortType::Int)]
    public int $count;

    public function execute(NodeContext $context): NodeResult
    {
        $seed = $context->inputs['seed'];

        if (! is_int($seed)) {
            return NodeResult::failed(new InvalidArgumentException('Input [seed] must be an int.'));
        }

        return NodeResult::success(['count' => $seed + 1]);
    }
}
