<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

final class ApprovalPayloadCapturingHandler implements FlowStepHandler
{
    public static int $callCount = 0;

    /**
     * @var array<string, array<string, mixed>>
     */
    public static array $lastStepOutputs = [];

    public static function reset(): void
    {
        self::$callCount = 0;
        self::$lastStepOutputs = [];
    }

    public function execute(FlowContext $context): FlowStepResult
    {
        self::$callCount++;
        self::$lastStepOutputs = $context->stepOutputs;

        return FlowStepResult::success([
            'approval' => $context->stepOutputs['manager'] ?? [],
        ]);
    }
}
