<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Static recorder for compensator order assertions.
 */
final class RecordingCompensator implements FlowCompensator
{
    /**
     * @var list<array{flowRunId: string, definitionName: string, originalOutput: array<string, mixed>}>
     */
    public static array $invocations = [];

    public static function reset(): void
    {
        self::$invocations = [];
    }

    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        self::$invocations[] = [
            'flowRunId' => $context->flowRunId,
            'definitionName' => $context->definitionName,
            'originalOutput' => $stepResult->output,
        ];
    }
}
