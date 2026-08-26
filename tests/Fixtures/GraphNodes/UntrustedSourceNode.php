<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortProvenance;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Stands in for an LLM node, a page fetcher, a mail ingester: something
 * whose output is words an attacker could have chosen.
 */
#[FlowNode(type: 'test.untrusted_source', category: 'testing')]
final class UntrustedSourceNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: false)]
    public string $prompt = '';

    #[Output(type: PortType::Text, provenance: PortProvenance::Untrusted)]
    public string $text;

    /** @var array<string, mixed> */
    #[Output(type: PortType::Json, provenance: PortProvenance::Untrusted)]
    public array $payload;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success([
            'text' => 'whatever the other side said',
            'payload' => ['said' => 'whatever the other side said'],
        ]);
    }
}
