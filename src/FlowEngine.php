<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Padosoft\LaravelFlow\Contracts\FlowStore;
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
 * dryRun. Definitions stay in-memory; v0.2 can optionally persist runtime
 * runs, steps, and audit records when configured.
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
        /**
         * @var array{
         *     compensation_strategy?: string,
         *     audit_trail_enabled?: bool,
         *     dry_run_default?: bool,
         *     persistence?: array{enabled?: bool}
         * }
         */
        private readonly array $config = [],
        private readonly ?FlowStore $store = null,
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
        $persist = $this->shouldPersist($dryRun);
        $startedAt = $this->now();

        $run = new FlowRun(
            id: $this->generateId(),
            definitionName: $definition->name,
            dryRun: $dryRun,
            startedAt: $startedAt,
        );
        $run->markRunning();
        $this->persistRunStarted($persist, $run, $input);

        $context = new FlowContext(
            flowRunId: $run->id,
            definitionName: $definition->name,
            input: $input,
            stepOutputs: [],
            dryRun: $dryRun,
        );

        $completedSteps = [];
        $sequence = 0;

        foreach ($definition->steps as $step) {
            $sequence++;
            $stepStartedAt = $this->now();
            $this->persistStepStarted($persist, $run, $step, $sequence, $context, $stepStartedAt);
            $this->recordAudit($persist, 'FlowStepStarted', $run, $step->name, [
                'definition_name' => $definition->name,
                'dry_run' => $dryRun,
                'status' => 'running',
            ]);
            $this->dispatchOrPersistListenerFailure(
                $persist,
                new FlowStepStarted($run->id, $definition->name, $step->name, $dryRun),
                $run,
                $step,
                $sequence,
                $context,
                $stepStartedAt,
                false,
            );

            $result = $this->executeStep($step, $context);
            $stepFinishedAt = $this->now();
            $run->recordStepResult($step->name, $result);

            if (! $result->success) {
                $this->persistStepFinished(
                    $persist,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $result,
                    $stepStartedAt,
                    $stepFinishedAt,
                );
                $error = $result->error;
                $this->recordAudit($persist, 'FlowStepFailed', $run, $step->name, [
                    'definition_name' => $definition->name,
                    'dry_run' => $dryRun,
                    'error_class' => $error instanceof Throwable ? $error::class : null,
                    'error_message' => $error instanceof Throwable ? $error->getMessage() : null,
                    'status' => 'failed',
                ]);
                $this->dispatchOrPersistListenerFailure(
                    $persist,
                    new FlowStepFailed($run->id, $definition->name, $step->name, $result, $dryRun),
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $stepStartedAt,
                    true,
                );
                $run->markFailed($step->name, $stepFinishedAt);
                $this->persistRunFinished($persist, $run);

                try {
                    $this->compensate($definition, $context, $completedSteps, $run, $persist);
                } catch (Throwable $e) {
                    $this->persistRunFinished($persist, $run, 'failed');

                    throw $e;
                }

                $this->persistRunFinished($persist, $run, $run->compensated ? 'succeeded' : null);

                return $run;
            }

            $this->persistStepFinished(
                $persist,
                $run,
                $step,
                $sequence,
                $context,
                $result,
                $stepStartedAt,
                $stepFinishedAt,
            );
            $this->recordAudit($persist, 'FlowStepCompleted', $run, $step->name, [
                'definition_name' => $definition->name,
                'dry_run' => $dryRun,
                'dry_run_skipped' => $result->dryRunSkipped,
                'output' => $result->output,
                'status' => $result->dryRunSkipped ? 'skipped' : 'succeeded',
            ], $result->businessImpact);
            $this->dispatchOrPersistListenerFailure(
                $persist,
                new FlowStepCompleted($run->id, $definition->name, $step->name, $result, $dryRun),
                $run,
                $step,
                $sequence,
                $context,
                $stepStartedAt,
                true,
            );

            // Accumulate output into context for downstream steps (skip dry-run-skipped).
            if (! $result->dryRunSkipped) {
                $context = $context->withStepOutput($step->name, $result->output);
            }

            $completedSteps[] = $step;
        }

        $run->markSucceeded($this->now());
        $this->persistRunFinished($persist, $run);

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
    private function compensate(
        FlowDefinition $definition,
        FlowContext $context,
        array $completedSteps,
        FlowRun $run,
        bool $persist,
    ): void {
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

            $this->recordAudit($persist, 'FlowCompensated', $run, $step->name, [
                'definition_name' => $definition->name,
                'dry_run' => $context->dryRun,
                'status' => 'compensated',
            ]);
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

    private function dispatchOrPersistListenerFailure(
        bool $persist,
        object $event,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        DateTimeInterface $stepStartedAt,
        bool $stepAlreadyFinished,
    ): void {
        if (! $persist) {
            $this->dispatch($event);

            return;
        }

        try {
            $this->dispatch($event);
        } catch (Throwable $e) {
            $failedAt = $this->now();

            if (! $stepAlreadyFinished) {
                $result = FlowStepResult::failed($e);
                $run->recordStepResult($step->name, $result);
                $this->persistStepFinished(
                    true,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $result,
                    $stepStartedAt,
                    $failedAt,
                );
            }

            if ($run->finishedAt === null) {
                $run->markFailed($step->name, $failedAt);
            }

            $this->persistRunFinished(true, $run);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persistRunStarted(bool $persist, FlowRun $run, array $input): void
    {
        $store = $this->storeFor($persist);

        if ($store === null) {
            return;
        }

        $store->runs()->create([
            'definition_name' => $run->definitionName,
            'dry_run' => $run->dryRun,
            'id' => $run->id,
            'input' => $input,
            'started_at' => $run->startedAt,
            'status' => $run->status,
        ]);
    }

    private function persistRunFinished(bool $persist, FlowRun $run, ?string $compensationStatus = null): void
    {
        $store = $this->storeFor($persist);

        if ($store === null) {
            return;
        }

        $store->runs()->update($run->id, [
            'business_impact' => $this->runBusinessImpact($run),
            'compensated' => $run->compensated,
            'compensation_status' => $compensationStatus,
            'duration_ms' => $this->durationMs($run->startedAt, $run->finishedAt),
            'failed_step' => $run->failedStep,
            'finished_at' => $run->finishedAt,
            'output' => $this->runOutput($run),
            'status' => $run->status,
        ]);
    }

    private function persistStepStarted(
        bool $persist,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        DateTimeInterface $startedAt,
    ): void {
        $store = $this->storeFor($persist);

        if ($store === null) {
            return;
        }

        $store->steps()->createOrUpdate($run->id, $step->name, [
            'dry_run_skipped' => false,
            'handler' => $step->handlerFqcn,
            'input' => [
                'flow_input' => $context->input,
                'step_outputs' => $context->stepOutputs,
            ],
            'sequence' => $sequence,
            'started_at' => $startedAt,
            'status' => 'running',
        ]);
    }

    private function persistStepFinished(
        bool $persist,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        FlowStepResult $result,
        DateTimeInterface $startedAt,
        DateTimeInterface $finishedAt,
    ): void {
        $store = $this->storeFor($persist);

        if ($store === null) {
            return;
        }

        $error = $result->error;

        $store->steps()->createOrUpdate($run->id, $step->name, [
            'business_impact' => $result->businessImpact,
            'duration_ms' => $this->durationMs($startedAt, $finishedAt),
            'dry_run_skipped' => $result->dryRunSkipped,
            'error_class' => $error instanceof Throwable ? $error::class : null,
            'error_message' => $error instanceof Throwable ? $error->getMessage() : null,
            'finished_at' => $finishedAt,
            'handler' => $step->handlerFqcn,
            'input' => [
                'flow_input' => $context->input,
                'step_outputs' => $context->stepOutputs,
            ],
            'output' => $result->success ? $result->output : null,
            'sequence' => $sequence,
            'started_at' => $startedAt,
            'status' => $result->success ? ($result->dryRunSkipped ? 'skipped' : 'succeeded') : 'failed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $businessImpact
     */
    private function recordAudit(
        bool $persist,
        string $event,
        FlowRun $run,
        ?string $stepName,
        array $payload,
        ?array $businessImpact = null,
    ): void {
        $store = $this->storeFor($persist);

        if ($store === null || ! $this->auditEnabled()) {
            return;
        }

        $store->audit()->append(
            runId: $run->id,
            event: $event,
            payload: $payload,
            stepName: $stepName,
            businessImpact: $businessImpact,
        );
    }

    private function shouldPersist(bool $dryRun): bool
    {
        $persistence = $this->config['persistence'] ?? [];

        return ! $dryRun
            && is_array($persistence)
            && (bool) ($persistence['enabled'] ?? false)
            && $this->store instanceof FlowStore;
    }

    private function storeFor(bool $persist): ?FlowStore
    {
        if (! $persist || ! $this->store instanceof FlowStore) {
            return null;
        }

        return $this->store;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runOutput(FlowRun $run): array
    {
        $output = [];

        foreach ($run->stepResults as $stepName => $result) {
            if ($result->dryRunSkipped || $result->output === []) {
                continue;
            }

            $output[$stepName] = $result->output;
        }

        return $output;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function runBusinessImpact(FlowRun $run): ?array
    {
        $businessImpact = [];

        foreach ($run->stepResults as $stepName => $result) {
            if ($result->businessImpact === null) {
                continue;
            }

            $businessImpact[$stepName] = $result->businessImpact;
        }

        return $businessImpact === [] ? null : $businessImpact;
    }

    private function durationMs(DateTimeInterface $startedAt, ?DateTimeInterface $finishedAt): ?int
    {
        if ($finishedAt === null) {
            return null;
        }

        $started = (float) $startedAt->format('U.u');
        $finished = (float) $finishedAt->format('U.u');

        return max(0, (int) round(($finished - $started) * 1000));
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
        if (! $this->auditEnabled()) {
            return;
        }

        $this->events->dispatch($event);
    }

    private function auditEnabled(): bool
    {
        return (bool) ($this->config['audit_trail_enabled'] ?? true);
    }

    private function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
