<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use RuntimeException;

/**
 * Redaction fixture: fails with an exception message embedding secrets in the
 * same shapes v1's `SecretFailsHandler` stub exercises (bare key=value,
 * Bearer token, quoted-JSON key:value) — proves NodeExecutor persists a
 * sanitized `error_message`, not the raw exception text.
 */
#[FlowNode(type: 'test.secretfail', category: 'testing')]
final class SecretFailingNode implements FlowNodeHandler
{
    #[Input(type: PortType::Json, required: false)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::failed(new RuntimeException(
            'gateway rejected token=plain-secret apiKey=camel-secret authorization=Bearer auth-secret {"api-key":"json-dash-secret"}',
        ));
    }
}
