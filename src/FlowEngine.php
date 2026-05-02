<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Throwable;

/**
 * Main entry point for laravel-flow.
 *
 * Holds the registry of {@see FlowDefinition}s and exposes execute /
 * dryRun. Definitions are stored in-memory in v0.1; v0.2 will optionally
 * persist them to a `flow_definitions` table.
 */
class FlowEngine
{
    /**
     * @var array<string, FlowDefinition>
     */
    private array $definitions = [];

    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $events,
        /** @var array{compensation_strategy?: string, audit_trail_enabled?: bool, dry_run_default?: bool} */
        private readonly array $config = [],
    ) {}

    public function define(string $name): FlowDefinitionBuilder
    {
        return new FlowDefinitionBuilder($this, $name);
    }

    public function registerDefinition(FlowDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
    }

    /**
     * @return array<string, FlowDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $name): FlowDefinition
    {
        if (! isset($this->definitions[$name])) {
            throw new FlowNotRegisteredException(sprintf(
                'Flow definition [%s] is not registered.',
                $name,
            ));
        }

        return $this->definitions[$name];
    }

    /**
     * Execute a registered flow with the given input.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(string $name, array $input): FlowRun
    {
        $dryRunDefault = (bool) ($this->config['dry_run_default'] ?? false);

        return $this->run($name, $input, $dryRunDefault);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function dryRun(string $name, array $input): FlowRun
    {
        return $this->run($name, $input, true);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function run(string $name, array $input, bool $dryRun): FlowRun
    {
        $definition = $this->definition($name);
        $this->validateInput($definition, $input);

        $run = new FlowRun(
            id: $this->generateId(),
            definitionName: $definition->name,
            dryRun: $dryRun,
            startedAt: $this->now(),
        );
        $run->markRunning();

        $context = new FlowContext(
            flowRunId: $run->id,
            definitionName: $definition->name,
            input: $input,
            stepOutputs: [],
            dryRun: $dryRun,
        );

        $completedSteps = [];

        foreach ($definition->steps as $step) {
            $this->dispatch(new FlowStepStarted($run->id, $definition->name, $step->name, $dryRun));

            $result = $this->executeStep($step, $context);
            $run->recordStepResult($step->name, $result);

            if (! $result->success) {
                $this->dispatch(new FlowStepFailed($run->id, $definition->name, $step->name, $result, $dryRun));
                $run->markFailed($step->name, $this->now());
                $this->compensate($definition, $context, $completedSteps, $run);

                return $run;
            }

            $this->dispatch(new FlowStepCompleted($run->id, $definition->name, $step->name, $result, $dryRun));

            // Accumulate output into context for downstream steps (skip dry-run-skipped).
            if (! $result->dryRunSkipped) {
                $context = $context->withStepOutput($step->name, $result->output);
            }

            $completedSteps[] = $step;
        }

        $run->markSucceeded($this->now());

        return $run;
    }

    private function executeStep(FlowStep $step, FlowContext $context): FlowStepResult
    {
        if ($context->dryRun && ! $step->supportsDryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        try {
            $handler = $this->container->make($step->handlerFqcn);
        } catch (Throwable $e) {
            return FlowStepResult::failed(new FlowExecutionException(
                sprintf('Cannot resolve handler [%s] for step [%s]: %s', $step->handlerFqcn, $step->name, $e->getMessage()),
                previous: $e,
            ));
        }

        if (! $handler instanceof FlowStepHandler) {
            return FlowStepResult::failed(new FlowExecutionException(sprintf(
                'Handler [%s] for step [%s] does not implement %s.',
                $step->handlerFqcn,
                $step->name,
                FlowStepHandler::class,
            )));
        }

        try {
            return $handler->execute($context);
        } catch (Throwable $e) {
            return FlowStepResult::failed($e);
        }
    }

    /**
     * Walk the previously-completed steps backwards and compensate.
     *
     * @param  list<FlowStep>  $completedSteps  steps that finished BEFORE the failure (the failing step is included if it had any compensator-relevant side effect — currently we exclude it for clarity).
     */
    private function compensate(FlowDefinition $definition, FlowContext $context, array $completedSteps, FlowRun $run): void
    {
        // 'parallel' compensation strategy is reserved for v0.2; always reverse-order for now.
        $reversed = array_reverse($completedSteps);

        $compensatedAtLeastOne = false;

        /** @var list<array{step:string,error:Throwable}> $compensationErrors */
        $compensationErrors = [];

        foreach ($reversed as $step) {
            if ($step->compensatorFqcn === null) {
                continue;
            }

            $stepResult = $run->stepResults[$step->name] ?? null;
            if ($stepResult === null) {
                continue;
            }

            try {
                $compensator = $this->container->make($step->compensatorFqcn);
            } catch (Throwable $e) {
                // Record the failure but KEEP GOING — the whole point of saga
                // compensation is best-effort rollback of every prior step. An
                // early throw here would leave the FIRST steps unapplied for
                // rollback exactly when partial-failure rollback matters most.
                $compensationErrors[] = ['step' => $step->name, 'error' => $e];

                continue;
            }

            if (! $compensator instanceof FlowCompensator) {
                $compensationErrors[] = [
                    'step' => $step->name,
                    'error' => new FlowCompensationException(sprintf(
                        'Compensator [%s] for step [%s] does not implement %s.',
                        $step->compensatorFqcn,
                        $step->name,
                        FlowCompensator::class,
                    )),
                ];

                continue;
            }

            try {
                $compensator->compensate($context, $stepResult);
            } catch (Throwable $e) {
                $compensationErrors[] = ['step' => $step->name, 'error' => $e];

                continue;
            }

            $this->dispatch(new FlowCompensated($run->id, $definition->name, $step->name, $context->dryRun));
            $compensatedAtLeastOne = true;
        }

        if ($compensatedAtLeastOne) {
            $run->markCompensated();
        }

        if ($compensationErrors !== []) {
            $summary = implode('; ', array_map(
                static fn (array $entry): string => sprintf(
                    '[%s] %s',
                    $entry['step'],
                    $entry['error']->getMessage(),
                ),
                $compensationErrors,
            ));

            throw new FlowCompensationException(sprintf(
                'Flow [%s] compensation completed with %d failed compensator(s): %s',
                $definition->name,
                count($compensationErrors),
                $summary,
            ), previous: $compensationErrors[0]['error']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateInput(FlowDefinition $definition, array $input): void
    {
        $missing = [];

        foreach ($definition->requiredInputs as $key) {
            if (! array_key_exists($key, $input)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new FlowInputException(sprintf(
                'Flow [%s] is missing required input keys: %s',
                $definition->name,
                implode(', ', $missing),
            ));
        }
    }

    private function dispatch(object $event): void
    {
        $auditEnabled = (bool) ($this->config['audit_trail_enabled'] ?? true);

        if (! $auditEnabled) {
            return;
        }

        $this->events->dispatch($event);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
