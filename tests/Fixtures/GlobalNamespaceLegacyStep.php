<?php

declare(strict_types=1);

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Global-namespace v1 step fixture: pins LegacyStepNodeAdapter's basename
 * handling for classes without a namespace separator. Loaded explicitly by
 * the test (composer classmap does not cover tests/), never autoloaded.
 */
final class GlobalNamespaceLegacyStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        return FlowStepResult::success();
    }
}
