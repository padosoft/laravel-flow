<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Compensator that always throws when invoked. Used to exercise the
 * "compensation continues on error" behaviour: the engine MUST keep
 * walking the remaining compensators in the loop after this one fires
 * and surface an aggregated FlowCompensationException at the end.
 */
final class ThrowingCompensator implements FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        throw new RuntimeException(sprintf(
            'ThrowingCompensator: simulated rollback failure (flowRunId=%s, definition=%s)',
            $context->flowRunId,
            $context->definitionName,
        ));
    }
}
