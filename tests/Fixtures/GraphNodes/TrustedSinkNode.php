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

/**
 * Stands in for the dangerous end: a shell command, a tool name, a URL
 * fetched with our credentials attached. `command` refuses untrusted data;
 * `note` does not, so one node can show both sides of the rule.
 */
#[FlowNode(type: 'test.trusted_sink', category: 'testing')]
final class TrustedSinkNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true, requiresTrusted: true)]
    public string $command;

    #[Input(type: PortType::Text, required: false)]
    public string $note = '';

    #[Output(type: PortType::Text)]
    public string $result;

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['result' => 'ran']);
    }
}
