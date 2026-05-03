<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

final class FirstStepCompensator implements FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        RecordingCompensator::$invocations[] = [
            'flowRunId' => $context->flowRunId,
            'definitionName' => $context->definitionName,
            'originalOutput' => array_merge($stepResult->output, ['compensator' => 'first']),
            'stepOutputs' => $context->stepOutputs,
        ];
    }
}
