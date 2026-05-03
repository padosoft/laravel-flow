<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

final class ClockAdvancingThrowingCompensator implements FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        Date::setTestNow(Carbon::parse('2026-05-02 10:00:05'));

        throw new RuntimeException('clock-advanced rollback failure');
    }
}
