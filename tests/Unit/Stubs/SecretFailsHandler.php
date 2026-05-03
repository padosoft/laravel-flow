<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

final class SecretFailsHandler implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        return FlowStepResult::failed(new RuntimeException(
            'gateway rejected token=plain-secret apiKey=camel-secret api-key=dash-secret authorization=Bearer auth-secret Bearer abc123 {"api-key":"json-dash-secret","apiKey":"json-camel-secret"}',
        ));
    }
}
