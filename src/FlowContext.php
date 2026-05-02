<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * Readonly context object passed to every handler + compensator.
 *
 * @phpstan-type StepOutputs array<string, array<string, mixed>>
 */
final class FlowContext
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $stepOutputs  keyed by step name
     */
    public function __construct(
        public readonly string $flowRunId,
        public readonly string $definitionName,
        public readonly array $input,
        public readonly array $stepOutputs = [],
        public readonly bool $dryRun = false,
    ) {}

    /**
     * Return a new context with `$stepOutputs[$stepName]` set to `$output`.
     *
     * @param  array<string, mixed>  $output
     */
    public function withStepOutput(string $stepName, array $output): self
    {
        $next = $this->stepOutputs;
        $next[$stepName] = $output;

        return new self(
            $this->flowRunId,
            $this->definitionName,
            $this->input,
            $next,
            $this->dryRun,
        );
    }
}
