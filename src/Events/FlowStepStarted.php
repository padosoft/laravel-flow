<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Events;

final class FlowStepStarted
{
    public function __construct(
        public readonly string $flowRunId,
        public readonly string $definitionName,
        public readonly string $stepName,
        public readonly bool $dryRun,
    ) {}
}
