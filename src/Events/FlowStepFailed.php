<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Events;

use Padosoft\LaravelFlow\FlowStepResult;

/**
 * @api
 */
final class FlowStepFailed
{
    public function __construct(
        public readonly string $flowRunId,
        public readonly string $definitionName,
        public readonly string $stepName,
        public readonly FlowStepResult $result,
        public readonly bool $dryRun,
    ) {}
}
