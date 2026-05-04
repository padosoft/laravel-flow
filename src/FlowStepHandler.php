<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * Contract for every step handler.
 *
 * Implementations are resolved through the Laravel container, so they
 * may type-hint dependencies in `__construct`.
 *
 * - The handler MUST honour `$context->dryRun` when `supportsDryRun` is
 *   true on the step definition. When dry-run is on, the handler MUST
 *   NOT mutate persistent state; it should return projected impact
 *   under {@see FlowStepResult::$businessImpact}.
 * - The handler returns a {@see FlowStepResult}. To signal failure,
 *   either return `FlowStepResult::failed(...)` or throw — the engine
 *   catches both and routes to compensation.
 * - `FlowStepResult::paused(...)` is reserved for side-effect-free
 *   control steps. A paused step is not considered completed and its own
 *   compensator will not run during rollback.
 */
interface FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult;
}
