<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

final class ThrowingHandler implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        throw new RuntimeException('handler exploded');
    }
}
