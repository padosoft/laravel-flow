<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

final class AlwaysSucceedsHandler implements FlowStepHandler
{
    public static int $callCount = 0;

    public function execute(FlowContext $context): FlowStepResult
    {
        self::$callCount++;

        return FlowStepResult::success(['handler' => self::class, 'flow_run_id' => $context->flowRunId]);
    }
}
