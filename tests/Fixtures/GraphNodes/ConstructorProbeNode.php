<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Executor\Attributes\Cost;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Planner fixture: counts constructor invocations so a test can prove the
 * dry-run planner NEVER constructs a handler (registration reflects the class
 * without instantiating it; only real execution builds handlers).
 */
#[FlowNode(type: 'test.ctorprobe', category: 'testing')]
final class ConstructorProbeNode implements FlowNodeHandler
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }

    #[Input(type: PortType::Json, required: false, multiple: true)]
    public array $in = [];

    #[Output(type: PortType::Json)]
    public array $out;

    #[Cost(estimate: ['tokens' => 5])]
    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::success(['out' => []]);
    }
}
