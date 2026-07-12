<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Executor\Attributes\Cacheable;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Cacheable fixture that PAUSES (awaits external input). A paused result carries
 * `success === true`, so it exercises the guard that must keep partial paused
 * output out of the node cache. Counts invocations so a test can assert a second
 * run still runs the handler (no bogus cache hit).
 */
#[FlowNode(type: 'test.cache.pausing', category: 'testing')]
final class CacheablePausingNode implements FlowNodeHandler
{
    public static int $invocations = 0;

    #[Input(type: PortType::Int, required: false)]
    public int $value = 0;

    #[Output(type: PortType::Int)]
    public int $partial;

    #[Cacheable]
    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations++;

        $value = is_numeric($context->inputs['value'] ?? null) ? (int) $context->inputs['value'] : 0;

        return NodeResult::paused(['partial' => $value]);
    }
}
