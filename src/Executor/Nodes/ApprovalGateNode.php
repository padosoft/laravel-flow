<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Nodes;

use Padosoft\LaravelFlow\ApprovalGate;
use Padosoft\LaravelFlow\Executor\GraphApprovalCoordinator;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Built-in pause primitive: always registered as `flow.approval`. Mirrors v1's
 * {@see ApprovalGate} step but as a graph node — it
 * carries no dependencies itself; {@see NodeExecutor}
 * detects a paused execution of THIS class and issues the one-time approval
 * token (hash-only storage), exactly mirroring how v1's engine (not the step)
 * owns token issuance for `ApprovalGate::class`.
 *
 * The `out` port carries the approval DECISION payload once resumed — set by
 * {@see GraphApprovalCoordinator} when the run
 * is approved, never populated while paused (downstream cannot be ready until
 * then, so there is nothing to route).
 *
 * @api
 */
#[FlowNode(type: 'flow.approval', category: 'control')]
final class ApprovalGateNode implements FlowNodeHandler
{
    /** @var array<string, mixed> */
    #[Input(type: PortType::Json, required: false)]
    public array $in = [];

    /** @var array<string, mixed> */
    #[Output(type: PortType::Json)]
    public array $out;

    public function execute(NodeContext $context): NodeResult
    {
        // Dry-run must have zero side effects: no pause, no token issuance —
        // mirrors every other control node's dry-run contract.
        if ($context->dryRun) {
            return NodeResult::dryRunSkipped();
        }

        return NodeResult::paused(['approval_required' => true]);
    }
}
