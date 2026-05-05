<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * Built-in step handler that pauses a flow until a later resume/reject slice
 * decides the pending approval.
 *
 * @api
 */
final class ApprovalGate implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        return FlowStepResult::paused([
            'approval_required' => true,
        ]);
    }
}
