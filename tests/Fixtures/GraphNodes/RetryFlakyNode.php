<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Executor\Attributes\Retry;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use RuntimeException;

#[FlowNode(type: 'test.flaky', category: 'testing')]
#[Retry(tries: 3)]
final class RetryFlakyNode implements FlowNodeHandler
{
    public static int $calls = 0;

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        self::$calls++;

        if (self::$calls < 3) {
            return NodeResult::failed(new RuntimeException('flaky attempt '.self::$calls));
        }

        return NodeResult::success(['out' => ['calls' => self::$calls]]);
    }
}
