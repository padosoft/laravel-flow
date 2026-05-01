<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;

/**
 * Fluent builder returned by {@see FlowEngine::define()}.
 *
 * The builder is mutable while assembling and produces an immutable
 * {@see FlowDefinition} on `register()`. `withDryRun()` and
 * `compensateWith()` apply to the LAST step added — calling them before
 * the first `step()` is an error.
 */
final class FlowDefinitionBuilder
{
    /**
     * @var list<string>
     */
    private array $requiredInputs = [];

    /**
     * @var list<FlowStep>
     */
    private array $steps = [];

    private ?string $aggregateCompensatorFqcn = null;

    public function __construct(
        private readonly FlowEngine $engine,
        private readonly string $name,
    ) {}

    /**
     * @param  list<string>  $required
     */
    public function withInput(array $required): self
    {
        $this->requiredInputs = array_values(array_unique($required));

        return $this;
    }

    /**
     * Append a step to the definition.
     *
     * @param  class-string<FlowStepHandler>  $handlerFqcn
     */
    public function step(string $name, string $handlerFqcn): self
    {
        $this->steps[] = new FlowStep($name, $handlerFqcn);

        return $this;
    }

    /**
     * Toggle dry-run support on the LAST step added.
     */
    public function withDryRun(bool $supports = true): self
    {
        $this->mutateLastStep(fn (FlowStep $s): FlowStep => $s->withDryRun($supports));

        return $this;
    }

    /**
     * Attach a compensator to the LAST step added.
     *
     * @param  class-string<FlowCompensator>  $compensatorFqcn
     */
    public function compensateWith(string $compensatorFqcn): self
    {
        $this->mutateLastStep(fn (FlowStep $s): FlowStep => $s->withCompensator($compensatorFqcn));

        return $this;
    }

    /**
     * Aggregate compensator for the whole flow (runs once at the very end
     * if any step failed and reverse-order compensation didn't fully
     * unwind). Reserved for v0.2.
     *
     * @param  class-string<FlowCompensator>  $compensatorFqcn
     */
    public function withAggregateCompensator(string $compensatorFqcn): self
    {
        $this->aggregateCompensatorFqcn = $compensatorFqcn;

        return $this;
    }

    public function register(): void
    {
        if ($this->steps === []) {
            throw new FlowExecutionException(sprintf(
                'Flow definition [%s] cannot be registered with zero steps.',
                $this->name,
            ));
        }

        $definition = new FlowDefinition(
            $this->name,
            $this->requiredInputs,
            $this->steps,
            $this->aggregateCompensatorFqcn,
        );

        $this->engine->registerDefinition($definition);
    }

    /**
     * @param  callable(FlowStep): FlowStep  $mutator
     */
    private function mutateLastStep(callable $mutator): void
    {
        $count = count($this->steps);
        if ($count === 0) {
            throw new FlowExecutionException(sprintf(
                'Cannot configure last step on flow [%s]: no step has been added yet.',
                $this->name,
            ));
        }

        $this->steps[$count - 1] = $mutator($this->steps[$count - 1]);
    }
}
