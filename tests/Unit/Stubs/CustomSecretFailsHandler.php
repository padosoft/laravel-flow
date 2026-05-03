<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

final class CustomSecretFailsHandler implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        return FlowStepResult::failed(new RuntimeException('custom policy leaked custom-secret'));
    }
}
