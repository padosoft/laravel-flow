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

#[FlowNode(type: 'test.alwaysfail', category: 'testing')]
final class RetryAlwaysFailNode implements FlowNodeHandler
{
    public static int $calls = 0;

    #[Output(type: PortType::Json)]
    public array $out;

    #[Retry(tries: 2, backoff: 5)]
    public function execute(NodeContext $context): NodeResult
    {
        self::$calls++;

        return NodeResult::failed(new RuntimeException('always fails'));
    }
}
