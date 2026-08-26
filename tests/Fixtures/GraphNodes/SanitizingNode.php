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
 * The only shape that legitimately launders taint: it reduces attacker
 * text to one of a closed set the graph author controls. `choice` is
 * trusted because it can only ever be one of two strings; `echo` is left
 * derived, because handing the input back in a different shape is not
 * sanitization.
 */
#[FlowNode(type: 'test.sanitize', category: 'testing')]
final class SanitizingNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true)]
    public string $candidate;

    #[Output(type: PortType::Text, provenance: PortProvenance::Trusted)]
    public string $choice;

    #[Output(type: PortType::Text)]
    public string $echo;

    public function execute(NodeContext $context): NodeResult
    {
        $candidate = $context->inputs['candidate'] ?? '';

        return NodeResult::success([
            'choice' => $candidate === 'archive' ? 'archive' : 'ignore',
            'echo' => is_string($candidate) ? $candidate : '',
        ]);
    }
}
