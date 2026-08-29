<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Routines;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/** Registra l'input che il flow ha davvero ricevuto. */
final class CapturingHandler implements FlowStepHandler
{
    /** @var array<string, mixed>|null */
    public static ?array $input = null;

    public function execute(FlowContext $context): FlowStepResult
    {
        self::$input = $context->input;

        return FlowStepResult::success([]);
    }
}
