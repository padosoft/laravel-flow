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
 * Cacheable fixture whose output carries a redacted-list key (`secret`). With
 * persistence redaction enabled, the cache write must be SKIPPED so a later
 * lookup misses and the handler runs again (a hit can never return the
 * placeholder a miss would not have produced).
 */
#[FlowNode(type: 'test.cache.secret', category: 'testing')]
final class CacheableSecretNode implements FlowNodeHandler
{
    public static int $invocations = 0;

    #[Input(type: PortType::Int, required: false)]
    public int $value = 0;

    #[Output(type: PortType::Json)]
    public array $result;

    #[Cacheable]
    public function execute(NodeContext $context): NodeResult
    {
        self::$invocations++;

        $value = is_numeric($context->inputs['value'] ?? null) ? (int) $context->inputs['value'] : 0;

        return NodeResult::success(['secret' => 'token-'.$value, 'value' => $value]);
    }
}
