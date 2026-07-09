<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Deterministic v1 step whose output depends only on stable, run-independent
 * data (never the run id), so the same flow produces byte-identical step
 * outputs through the v1 engine and through the compiled graph — the basis of
 * the graph/v1 equivalence oracle.
 */
final class OracleStepHandler implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        return FlowStepResult::success(['handled_by' => self::class, 'flow' => $context->definitionName]);
    }
}
