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
 * Cacheable fixture with a 60-second TTL: an entry served past its expiry must
 * miss and re-run the handler.
 */
#[FlowNode(type: 'test.cache.ttl', category: 'testing')]
final class CacheableTtlNode implements FlowNodeHandler
{
    public static int $invocations = 0;

    #[Input(type: PortType::Int, required: false)]
    public int $value = 0;

    #[Output(type: PortType::Int)]
    public int $echoed;

    #[Cacheable(ttl: 60)]
    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations++;

        $value = is_numeric($context->inputs['value'] ?? null) ? (int) $context->inputs['value'] : 0;

        return NodeResult::success(['echoed' => $value]);
    }
}
