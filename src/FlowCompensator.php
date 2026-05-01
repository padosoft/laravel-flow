<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * Contract for compensators (saga rollback handlers).
 *
 * Compensators receive the same context the original step ran with
 * AND the original step's result, so they can rollback what was
 * committed. Compensators MUST be idempotent — the engine may invoke
 * them more than once if the flow is replayed.
 */
interface FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void;
}
