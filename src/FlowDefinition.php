<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * Readonly aggregate describing a registered flow.
 *
 * @phpstan-type FlowSteps list<FlowStep>
 */
final class FlowDefinition
{
    /**
     * @param  list<string>  $requiredInputs
     * @param  list<FlowStep>  $steps
     */
    public function __construct(
        public readonly string $name,
        public readonly array $requiredInputs,
        public readonly array $steps,
        public readonly ?string $aggregateCompensatorFqcn = null,
    ) {}
}
