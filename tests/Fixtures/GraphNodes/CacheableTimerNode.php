<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Executor\Attributes\Cacheable;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * A #[Cacheable] node that waits: a timed pause must never be cached, because a
 * cache hit would serve an immediate success and skip both the handler and the
 * requested delay.
 */
#[FlowNode(type: 'test.cacheable_timer', category: 'testing')]
#[Cacheable]
final class CacheableTimerNode implements FlowNodeHandler
{
    public static int $executions = 0;

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        self::$executions++;

        return NodeResult::pausedUntil(Date::now()->addSeconds(2), ['out' => ['waited' => true]]);
    }
}
