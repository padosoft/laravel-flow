<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore;
use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Events\FlowPaused;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\Jobs\RunFlowJob;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
use Padosoft\LaravelFlow\Persistence\PayloadRedactorResolution;
use Padosoft\LaravelFlow\Queue\QueueRetryPolicy;
use Throwable;

/**
 * Main entry point for laravel-flow.
 *
 * Holds the registry of {@see FlowDefinition}s and exposes execute /
 * dryRun / dispatch. Definitions stay in-memory; v0.2 can optionally
 * persist runtime runs, steps, and audit records when configured.
 */
class FlowEngine
{
    private const COMPENSATION_STRATEGY_REVERSE_ORDER = 'reverse-order';

    private const COMPENSATION_STRATEGY_PARALLEL = 'parallel';

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
         *     compensation_parallel_driver?: string,
         *     audit_trail_enabled?: bool,
         *     dry_run_default?: bool,
         *     persistence?: array{
         *         enabled?: bool,
         *         redaction?: array{enabled?: bool, keys?: array<int, string>, replacement?: string}
         *     },
         *     queue?: array{
         *         lock_store?: string|null,
         *         lock_seconds?: int,
         *         lock_retry_seconds?: int,
         *         tries?: mixed,
         *         backoff_seconds?: mixed
         *     }
         * }
         */
        private readonly array $config = [],
        private readonly ?FlowStore $store = null,
        private readonly ?PayloadRedactor $redactor = null,
        private readonly mixed $clock = null,
        private readonly ?ConcurrencyDriver $compensationConcurrencyDriver = null,
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
    public function execute(string $name, array $input, ?FlowExecutionOptions $options = null): FlowRun
    {
        $dryRunDefault = (bool) ($this->config['dry_run_default'] ?? false);

        return $this->run($name, $input, $dryRunDefault, $options);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function dryRun(string $name, array $input, ?FlowExecutionOptions $options = null): FlowRun
    {
        return $this->run($name, $input, true, $options);
    }

    /**
     * Queue a registered flow for queued execution.
     *
     * @param  array<string, mixed>  $input
     */
    public function dispatch(string $name, array $input, ?FlowExecutionOptions $options = null): mixed
    {
        $definition = $this->definition($name);
        $this->validateInput($definition, $input);

        /** @var BusDispatcher $bus */
        $bus = $this->container->make(BusDispatcher::class);

        $retryPolicy = $this->queueRetryPolicy();
        $this->assertQueuedRunRetryPolicyIsSafe($retryPolicy, $options);

        return $bus->dispatch(new RunFlowJob(
            name: $definition->name,
            input: $input,
            options: $options,
            dispatchId: $this->generateId(),
            lockStore: $this->queueLockStore(),
            lockSeconds: $this->queueLockSeconds(),
            lockRetrySeconds: $this->queueLockRetrySeconds(),
            tries: $retryPolicy->tries,
            backoffSeconds: $retryPolicy->backoffSeconds,
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function run(string $name, array $input, bool $dryRun, ?FlowExecutionOptions $options = null): FlowRun
    {
        $options ??= new FlowExecutionOptions;
        $definition = $this->definition($name);
        $this->validateInput($definition, $input);
        $this->compensationStrategy();
        $store = $this->storeForExecution($dryRun);
        $redactor = $store instanceof FlowStore ? $this->redactorForExecution() : null;
        $store = $this->storeWithExecutionRedactor($store, $redactor);

        $existingRun = $this->existingRunForIdempotency($store, $definition, $options);

        if ($existingRun instanceof FlowRun) {
            return $existingRun;
        }

        $startedAt = $this->now();

        $run = new FlowRun(
            id: $this->generateId(),
            definitionName: $definition->name,
            dryRun: $dryRun,
            startedAt: $startedAt,
            correlationId: $options->correlationId,
            idempotencyKey: $options->idempotencyKey,
            replayedFromRunId: $options->replayedFromRunId,
        );
        $run->markRunning();

        try {
            $this->persistAtomically($store, function () use ($store, $run, $input): void {
                $this->persistRunStarted($store, $run, $input);
            });
        } catch (Throwable $e) {
            try {
                $existingRun = $this->existingRunForIdempotency($store, $definition, $options);
            } catch (Throwable) {
                throw $e;
            }

            if ($existingRun instanceof FlowRun) {
                return $existingRun;
            }

            throw $e;
        }

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
            $listenerFailureEvent = null;

            try {
                $this->persistAtomically($store, function () use (
                    $store,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $stepStartedAt,
                    $definition,
                    $dryRun,
                ): void {
                    $this->persistStepStarted($store, $run, $step, $sequence, $context, $stepStartedAt);
                    $this->recordAudit($store, 'FlowStepStarted', $run, $step->name, [
                        'definition_name' => $definition->name,
                        'dry_run' => $dryRun,
                        'status' => 'running',
                    ], occurredAt: $stepStartedAt);
                });
                $this->dispatchOrCaptureListenerFailure(
                    new FlowStepStarted($run->id, $definition->name, $step->name, $dryRun),
                    $listenerFailureEvent,
                );
            } catch (Throwable $e) {
                $failedAt = $this->now();
                $this->compensateAfterRuntimeAbort(
                    $definition,
                    $context,
                    $completedSteps,
                    $run,
                    $store,
                    $step,
                    $sequence,
                    FlowStepResult::failed($e),
                    $stepStartedAt,
                    $failedAt,
                    $redactor,
                    listenerEvent: $listenerFailureEvent,
                );

                throw $e;
            }

            $result = $this->executeStep($step, $context);
            $stepFinishedAt = $this->now();
            $run->recordStepResult($step->name, $result);

            if ($result->paused) {
                $run->markPaused();
                $listenerFailureEvent = null;

                try {
                    $this->persistAtomically($store, function () use (
                        $store,
                        $run,
                        $step,
                        $sequence,
                        $context,
                        $result,
                        $stepStartedAt,
                        $stepFinishedAt,
                        $definition,
                        $dryRun,
                        $redactor,
                    ): void {
                        $this->persistStepFinished(
                            $store,
                            $run,
                            $step,
                            $sequence,
                            $context,
                            $result,
                            $stepStartedAt,
                            $stepFinishedAt,
                            $redactor,
                        );
                        $this->recordAudit($store, 'FlowPaused', $run, $step->name, [
                            'definition_name' => $definition->name,
                            'dry_run' => $dryRun,
                            'output' => $result->output,
                            'status' => 'paused',
                        ], $result->businessImpact, $stepFinishedAt);
                        $this->persistRunFinished($store, $run);
                    });
                    $this->dispatchOrCaptureListenerFailure(
                        new FlowPaused($run->id, $definition->name, $step->name, $result, $dryRun),
                        $listenerFailureEvent,
                    );
                } catch (Throwable $e) {
                    $this->compensateAfterRuntimeAbort(
                        $definition,
                        $context,
                        $completedSteps,
                        $run,
                        $store,
                        $step,
                        $sequence,
                        FlowStepResult::failed($e),
                        $stepStartedAt,
                        $stepFinishedAt,
                        $redactor,
                        listenerEvent: $listenerFailureEvent,
                    );

                    throw $e;
                }

                return $run;
            }

            if (! $result->success) {
                $error = $result->error;
                $run->markFailed($step->name, $stepFinishedAt);
                $listenerFailureEvent = null;

                try {
                    $this->persistAtomically($store, function () use (
                        $store,
                        $run,
                        $step,
                        $sequence,
                        $context,
                        $result,
                        $stepStartedAt,
                        $stepFinishedAt,
                        $definition,
                        $dryRun,
                        $error,
                        $redactor,
                    ): void {
                        $this->persistStepFinished(
                            $store,
                            $run,
                            $step,
                            $sequence,
                            $context,
                            $result,
                            $stepStartedAt,
                            $stepFinishedAt,
                            $redactor,
                        );
                        $this->recordAudit($store, 'FlowStepFailed', $run, $step->name, [
                            'definition_name' => $definition->name,
                            'dry_run' => $dryRun,
                            'error_class' => $error instanceof Throwable ? $error::class : null,
                            'error_message' => $this->safeErrorMessage($error, $redactor),
                            'status' => 'failed',
                        ], occurredAt: $stepFinishedAt);
                        $this->persistRunFinished($store, $run);
                    });
                    $this->dispatchOrCaptureListenerFailure(
                        new FlowStepFailed($run->id, $definition->name, $step->name, $result, $dryRun),
                        $listenerFailureEvent,
                    );
                } catch (Throwable $e) {
                    $this->compensateAfterRuntimeAbort(
                        $definition,
                        $context,
                        $completedSteps,
                        $run,
                        $store,
                        $step,
                        $sequence,
                        $result,
                        $stepStartedAt,
                        $stepFinishedAt,
                        $redactor,
                        listenerEvent: $listenerFailureEvent,
                    );

                    throw $e;
                }

                try {
                    $this->compensate($definition, $context, $completedSteps, $run, $store);
                } catch (Throwable $e) {
                    $this->persistRunFinishedBestEffort($store, $run, 'failed');

                    throw $e;
                }

                if ($run->compensated) {
                    try {
                        $this->persistAtomically($store, function () use ($store, $run): void {
                            $this->persistRunFinished($store, $run, 'succeeded');
                        });
                    } catch (Throwable $e) {
                        $this->persistRunFinishedBestEffort($store, $run, 'succeeded');

                        throw $e;
                    }
                }

                return $run;
            }

            $contextAfterStep = $context;

            if (! $result->dryRunSkipped) {
                $contextAfterStep = $context->withStepOutput($step->name, $result->output);
            }

            $completedSteps[] = $step;
            $listenerFailureEvent = null;

            try {
                $this->persistAtomically($store, function () use (
                    $store,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $result,
                    $stepStartedAt,
                    $stepFinishedAt,
                    $definition,
                    $dryRun,
                    $redactor,
                ): void {
                    $this->persistStepFinished(
                        $store,
                        $run,
                        $step,
                        $sequence,
                        $context,
                        $result,
                        $stepStartedAt,
                        $stepFinishedAt,
                        $redactor,
                    );
                    $this->recordAudit($store, 'FlowStepCompleted', $run, $step->name, [
                        'definition_name' => $definition->name,
                        'dry_run' => $dryRun,
                        'dry_run_skipped' => $result->dryRunSkipped,
                        'output' => $result->output,
                        'status' => $result->dryRunSkipped ? 'skipped' : 'succeeded',
                    ], $result->businessImpact, $stepFinishedAt);
                });
                $this->dispatchOrCaptureListenerFailure(
                    new FlowStepCompleted($run->id, $definition->name, $step->name, $result, $dryRun),
                    $listenerFailureEvent,
                );
            } catch (Throwable $e) {
                $failedAt = $this->now();
                $this->compensateAfterRuntimeAbort(
                    $definition,
                    $contextAfterStep,
                    $completedSteps,
                    $run,
                    $store,
                    $step,
                    $sequence,
                    FlowStepResult::failed($e),
                    $stepStartedAt,
                    $failedAt,
                    $redactor,
                    listenerEvent: $listenerFailureEvent,
                    failedStepPersistenceContext: $context,
                );

                throw $e;
            }

            $context = $contextAfterStep;
        }

        try {
            $run->markSucceeded($this->now());
            $this->persistAtomically($store, function () use ($store, $run): void {
                $this->persistRunFinished($store, $run);
            });
        } catch (Throwable $e) {
            $this->compensateAfterRuntimeAbort(
                $definition,
                $context,
                $completedSteps,
                $run,
                $store,
                null,
                markRunAborted: true,
            );

            throw $e;
        }

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
        ?FlowStore $store,
    ): void {
        if ($context->dryRun) {
            return;
        }

        if ($this->compensationStrategy() === self::COMPENSATION_STRATEGY_PARALLEL) {
            $this->compensateParallel($definition, $context, $completedSteps, $run, $store);

            return;
        }

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

            $this->recordSuccessfulCompensation($definition, $context, $run, $store, $step);
            $compensatedAtLeastOne = true;
        }

        $this->finalizeCompensation($definition, $run, $compensationErrors, $compensatedAtLeastOne);
    }

    /**
     * @param  list<FlowStep>  $completedSteps
     */
    private function compensateParallel(
        FlowDefinition $definition,
        FlowContext $context,
        array $completedSteps,
        FlowRun $run,
        ?FlowStore $store,
    ): void {
        /** @var list<array{step:FlowStep,result:FlowStepResult,compensator:string}> $attempts */
        $attempts = [];

        foreach ($completedSteps as $step) {
            $compensatorFqcn = $step->compensatorFqcn;

            if ($compensatorFqcn === null) {
                continue;
            }

            $stepResult = $run->stepResults[$step->name] ?? null;

            if ($stepResult === null) {
                continue;
            }

            $attempts[] = [
                'step' => $step,
                'result' => $stepResult,
                'compensator' => $compensatorFqcn,
            ];
        }

        if ($attempts === []) {
            return;
        }

        /** @var array<int, Closure(): array{success:bool,error_class?:string,error_message?:string}> $driverTasks */
        $driverTasks = [];
        /** @var array<int, Closure(): array{success:bool,error_class?:string,error_message?:string}> $localTasks */
        $localTasks = [];

        foreach ($attempts as $index => $attempt) {
            $driverTasks[$index] = $this->globalParallelCompensationTask(
                $attempt['compensator'],
                $context,
                $attempt['result'],
            );
            $localTasks[$index] = $this->localParallelCompensationTask(
                $attempt['compensator'],
                $context,
                $attempt['result'],
            );
        }

        $results = $this->runParallelCompensationTasks($driverTasks, $localTasks);
        $compensatedAtLeastOne = false;

        /** @var list<array{step:string,error:Throwable}> $compensationErrors */
        $compensationErrors = [];

        foreach ($attempts as $index => $attempt) {
            $result = $results[$index] ?? null;
            $step = $attempt['step'];

            if (! is_array($result) || ($result['success'] ?? false) !== true) {
                $compensationErrors[] = [
                    'step' => $step->name,
                    'error' => $this->parallelCompensationError($result),
                ];

                continue;
            }

            $this->recordSuccessfulCompensation($definition, $context, $run, $store, $step);
            $compensatedAtLeastOne = true;
        }

        $this->finalizeCompensation($definition, $run, $compensationErrors, $compensatedAtLeastOne);
    }

    /**
     * @return Closure(): array{success:bool,error_class?:string,error_message?:string}
     */
    private function globalParallelCompensationTask(
        string $compensatorFqcn,
        FlowContext $context,
        FlowStepResult $stepResult,
    ): Closure {
        return static function () use ($compensatorFqcn, $context, $stepResult): array {
            return self::executeCompensationTask(
                \Illuminate\Container\Container::getInstance(),
                $compensatorFqcn,
                $context,
                $stepResult,
            );
        };
    }

    /**
     * @return Closure(): array{success:bool,error_class?:string,error_message?:string}
     */
    private function localParallelCompensationTask(
        string $compensatorFqcn,
        FlowContext $context,
        FlowStepResult $stepResult,
    ): Closure {
        $container = $this->container;

        return static function () use ($container, $compensatorFqcn, $context, $stepResult): array {
            return self::executeCompensationTask($container, $compensatorFqcn, $context, $stepResult);
        };
    }

    /**
     * @param  array<int, Closure(): array{success:bool,error_class?:string,error_message?:string}>  $driverTasks
     * @param  array<int, Closure(): array{success:bool,error_class?:string,error_message?:string}>  $localTasks
     * @return array<int, array{success:bool,error_class?:string,error_message?:string}>
     */
    private function runParallelCompensationTasks(array $driverTasks, array $localTasks): array
    {
        if ($this->compensationConcurrencyDriver instanceof ConcurrencyDriver && $this->containerIsGlobalInstance()) {
            try {
                /** @var array<int, array{success:bool,error_class?:string,error_message?:string}> $results */
                $results = $this->compensationConcurrencyDriver->run($driverTasks);

                return $results;
            } catch (Throwable) {
                // If the concurrency driver cannot run the batch, fall back to
                // local rollback rather than leaving side effects unapplied.
            }
        }

        $results = [];

        foreach ($localTasks as $index => $task) {
            $results[$index] = $task();
        }

        return $results;
    }

    /**
     * @return array{success:bool,error_class?:string,error_message?:string}
     */
    private static function executeCompensationTask(
        Container $container,
        string $compensatorFqcn,
        FlowContext $context,
        FlowStepResult $stepResult,
    ): array {
        try {
            $compensator = $container->make($compensatorFqcn);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }

        if (! $compensator instanceof FlowCompensator) {
            return [
                'success' => false,
                'error_class' => FlowCompensationException::class,
                'error_message' => sprintf(
                    'Compensator [%s] does not implement %s.',
                    $compensatorFqcn,
                    FlowCompensator::class,
                ),
            ];
        }

        try {
            $compensator->compensate($context, $stepResult);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }

        return ['success' => true];
    }

    private function containerIsGlobalInstance(): bool
    {
        return \Illuminate\Container\Container::getInstance() === $this->container;
    }

    /**
     * @param  array{success?:bool,error_class?:string,error_message?:string}|null  $result
     */
    private function parallelCompensationError(?array $result): Throwable
    {
        if ($result === null) {
            return new FlowCompensationException('Parallel compensation task did not return a result.');
        }

        $errorClass = $result['error_class'] ?? FlowCompensationException::class;
        $errorMessage = $result['error_message'] ?? 'Parallel compensation task failed.';

        return new FlowCompensationException(sprintf('%s: %s', $errorClass, $errorMessage));
    }

    private function recordSuccessfulCompensation(
        FlowDefinition $definition,
        FlowContext $context,
        FlowRun $run,
        ?FlowStore $store,
        FlowStep $step,
    ): void {
        $payload = [
            'definition_name' => $definition->name,
            'dry_run' => $context->dryRun,
            'status' => 'compensated',
        ];
        $compensatedAt = $this->now();
        $compensationAuditDurable = true;

        try {
            $this->recordAudit($store, 'FlowCompensated', $run, $step->name, $payload, occurredAt: $compensatedAt);
        } catch (Throwable) {
            // Persistence/audit outages must not interrupt rollback.
            $compensationAuditDurable = false;
        }

        if ($compensationAuditDurable) {
            $this->dispatchCompensatedAndIgnoreListenerFailure(
                $definition,
                $context,
                $run,
                $step,
            );
        }
    }

    /**
     * @param  list<array{step:string,error:Throwable}>  $compensationErrors
     */
    private function finalizeCompensation(
        FlowDefinition $definition,
        FlowRun $run,
        array $compensationErrors,
        bool $compensatedAtLeastOne,
    ): void {
        if ($compensationErrors !== []) {
            $run->finishedAt = $this->now();
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

        if ($compensatedAtLeastOne && $run->status === FlowRun::STATUS_ABORTED) {
            $run->compensated = true;
            $run->finishedAt = $this->now();
        } elseif ($compensatedAtLeastOne) {
            $run->markCompensated($this->now());
        }
    }

    private function compensationStrategy(): string
    {
        $strategy = trim((string) ($this->config['compensation_strategy'] ?? self::COMPENSATION_STRATEGY_REVERSE_ORDER));

        return match ($strategy) {
            self::COMPENSATION_STRATEGY_REVERSE_ORDER, self::COMPENSATION_STRATEGY_PARALLEL => $strategy,
            default => throw new FlowInputException(sprintf(
                'Unsupported compensation strategy [%s]. Supported strategies: %s, %s.',
                $strategy,
                self::COMPENSATION_STRATEGY_REVERSE_ORDER,
                self::COMPENSATION_STRATEGY_PARALLEL,
            )),
        };
    }

    /**
     * @param  list<FlowStep>  $completedSteps
     */
    private function compensateAfterRuntimeAbort(
        FlowDefinition $definition,
        FlowContext $context,
        array $completedSteps,
        FlowRun $run,
        ?FlowStore $store,
        ?FlowStep $failedStep,
        ?int $sequence = null,
        ?FlowStepResult $failedResult = null,
        ?DateTimeInterface $stepStartedAt = null,
        ?DateTimeImmutable $failedAt = null,
        ?PayloadRedactor $redactor = null,
        ?string $listenerEvent = null,
        ?FlowContext $failedStepPersistenceContext = null,
        bool $markRunAborted = false,
    ): void {
        $failedAt ??= $this->now();
        $failedStepPersistenceContext ??= $context;
        $shouldMarkRunFailed = $failedStep !== null
            && ! in_array($run->status, [FlowRun::STATUS_FAILED, FlowRun::STATUS_COMPENSATED], true);
        $shouldMarkRunAborted = $failedStep === null
            && $markRunAborted
            && ! in_array($run->status, [FlowRun::STATUS_FAILED, FlowRun::STATUS_COMPENSATED, FlowRun::STATUS_ABORTED], true);

        if ($shouldMarkRunFailed) {
            $run->markFailed($failedStep->name, $failedAt);
        } elseif ($shouldMarkRunAborted) {
            $run->markAborted($failedAt);
        }

        $failureTransitionAlreadyPersisted = $listenerEvent === 'FlowStepFailed'
            && $failedStep !== null
            && $run->status === FlowRun::STATUS_FAILED
            && $run->failedStep === $failedStep->name;

        $compensationError = null;
        try {
            $this->compensate($definition, $context, $completedSteps, $run, $store);
        } catch (Throwable $e) {
            $compensationError = $e;
        }

        $compensationStatus = $compensationError instanceof Throwable
            ? 'failed'
            : ($run->compensated ? 'succeeded' : null);
        $persistedRunState = false;

        if (
            $failedStep !== null
            && $sequence !== null
            && $failedResult instanceof FlowStepResult
            && $stepStartedAt instanceof DateTimeInterface
            && ! $failureTransitionAlreadyPersisted
        ) {
            $persistedRunState = $this->persistRuntimeAbortStateBestEffort(
                $store,
                $run,
                $failedStep,
                $sequence,
                $failedStepPersistenceContext,
                $failedResult,
                $stepStartedAt,
                $failedAt,
                $redactor,
                $listenerEvent,
                $compensationStatus,
            );
        } elseif ($shouldMarkRunFailed || $shouldMarkRunAborted) {
            $this->persistRunFinishedBestEffort($store, $run, $compensationStatus);
            $persistedRunState = true;
        }

        if (! $persistedRunState) {
            $this->persistRunFinishedBestEffort($store, $run, $compensationStatus);
        }
    }

    private function persistRuntimeAbortStateBestEffort(
        ?FlowStore $store,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        FlowStepResult $result,
        DateTimeInterface $startedAt,
        DateTimeInterface $failedAt,
        ?PayloadRedactor $redactor,
        ?string $listenerEvent,
        ?string $compensationStatus,
    ): bool {
        try {
            $this->persistAtomically($store, function () use (
                $store,
                $run,
                $step,
                $sequence,
                $context,
                $result,
                $startedAt,
                $failedAt,
                $redactor,
                $listenerEvent,
                $compensationStatus,
            ): void {
                $this->persistStepFinished(
                    $store,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $result,
                    $startedAt,
                    $failedAt,
                    $redactor,
                );

                $error = $result->error;
                $payload = [
                    'definition_name' => $context->definitionName,
                    'dry_run' => $context->dryRun,
                    'error_class' => $error instanceof Throwable ? $error::class : null,
                    'error_message' => $this->safeErrorMessage($error, $redactor),
                    'runtime_abort_recovery' => true,
                    'status' => 'failed',
                ];

                if ($listenerEvent !== null) {
                    $payload['listener_event'] = $listenerEvent;
                }

                $this->recordAudit($store, 'FlowStepFailed', $run, $step->name, $payload, occurredAt: $failedAt);

                $this->persistRunFinished($store, $run, $compensationStatus);
            });

            return true;
        } catch (Throwable) {
            $this->persistStepFailureTransitionBestEffort(
                $store,
                $run,
                $step,
                $sequence,
                $context,
                $result,
                $startedAt,
                $failedAt,
                $redactor,
                $listenerEvent,
            );

            return false;
        }
    }

    private function persistStepFailureTransitionBestEffort(
        ?FlowStore $store,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        FlowStepResult $result,
        DateTimeInterface $startedAt,
        DateTimeInterface $failedAt,
        ?PayloadRedactor $redactor,
        ?string $listenerEvent,
    ): void {
        try {
            $this->persistAtomically($store, function () use (
                $store,
                $run,
                $step,
                $sequence,
                $context,
                $result,
                $startedAt,
                $failedAt,
                $redactor,
                $listenerEvent,
            ): void {
                $this->persistStepFinished(
                    $store,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $result,
                    $startedAt,
                    $failedAt,
                    $redactor,
                );

                $error = $result->error;
                $payload = [
                    'definition_name' => $context->definitionName,
                    'dry_run' => $context->dryRun,
                    'error_class' => $error instanceof Throwable ? $error::class : null,
                    'error_message' => $this->safeErrorMessage($error, $redactor),
                    'runtime_abort_recovery' => true,
                    'status' => 'failed',
                ];

                if ($listenerEvent !== null) {
                    $payload['listener_event'] = $listenerEvent;
                }

                $this->recordAudit($store, 'FlowStepFailed', $run, $step->name, $payload, occurredAt: $failedAt);
            });
        } catch (Throwable) {
            $this->persistStepFinishedOnlyBestEffort(
                $store,
                $run,
                $step,
                $sequence,
                $context,
                $result,
                $startedAt,
                $failedAt,
                $redactor,
            );
        }
    }

    private function persistStepFinishedOnlyBestEffort(
        ?FlowStore $store,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        FlowStepResult $result,
        DateTimeInterface $startedAt,
        DateTimeInterface $failedAt,
        ?PayloadRedactor $redactor,
    ): void {
        try {
            $this->persistAtomically($store, function () use (
                $store,
                $run,
                $step,
                $sequence,
                $context,
                $result,
                $startedAt,
                $failedAt,
                $redactor,
            ): void {
                $this->persistStepFinished(
                    $store,
                    $run,
                    $step,
                    $sequence,
                    $context,
                    $result,
                    $startedAt,
                    $failedAt,
                    $redactor,
                );
            });
        } catch (Throwable) {
            // Preserve the original execution/listener/persistence exception.
        }
    }

    private function persistRunFinishedBestEffort(
        ?FlowStore $store,
        FlowRun $run,
        ?string $compensationStatus = null,
    ): void {
        try {
            $this->persistAtomically($store, function () use ($store, $run, $compensationStatus): void {
                $this->persistRunFinished($store, $run, $compensationStatus);
            });
        } catch (Throwable) {
            // Preserve the original execution/listener/persistence exception.
        }
    }

    private function dispatchOrCaptureListenerFailure(
        object $event,
        ?string &$listenerFailureEvent,
    ): void {
        try {
            $this->dispatchEvent($event);
        } catch (Throwable $e) {
            $listenerFailureEvent = $this->eventName($event);

            throw $e;
        }
    }

    private function dispatchCompensatedAndIgnoreListenerFailure(
        FlowDefinition $definition,
        FlowContext $context,
        FlowRun $run,
        FlowStep $step,
    ): void {
        try {
            $this->dispatchEvent(new FlowCompensated($run->id, $definition->name, $step->name, $context->dryRun));
        } catch (Throwable) {
            // Compensation listener failures must not interrupt rollback.
        }
    }

    private function eventName(object $event): string
    {
        $class = $event::class;
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * @param  callable(): void  $callback
     */
    private function persistAtomically(?FlowStore $store, callable $callback): void
    {
        if ($store === null) {
            return;
        }

        $store->transaction($callback);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persistRunStarted(?FlowStore $store, FlowRun $run, array $input): void
    {
        if ($store === null) {
            return;
        }

        $attributes = [
            'definition_name' => $run->definitionName,
            'correlation_id' => $run->correlationId,
            'dry_run' => $run->dryRun,
            'id' => $run->id,
            'idempotency_key' => $run->idempotencyKey,
            'input' => $input,
            'started_at' => $run->startedAt,
            'status' => $run->status,
        ];

        if ($run->replayedFromRunId !== null) {
            $attributes['replayed_from_run_id'] = $run->replayedFromRunId;
        }

        $store->runs()->create($attributes);
    }

    private function existingRunForIdempotency(
        ?FlowStore $store,
        FlowDefinition $definition,
        FlowExecutionOptions $options,
    ): ?FlowRun {
        if (! $store instanceof FlowStore || $options->idempotencyKey === null) {
            return null;
        }

        $existingRun = $store->runs()->findByIdempotencyKey($options->idempotencyKey);

        if (! $existingRun instanceof FlowRunRecord) {
            return null;
        }

        if ($existingRun->definition_name !== $definition->name) {
            throw new FlowExecutionException('The supplied idempotency key is already associated with a different flow definition.');
        }

        return $this->flowRunFromRecord($existingRun, $store);
    }

    private function flowRunFromRecord(FlowRunRecord $record, ?FlowStore $store = null): FlowRun
    {
        $run = new FlowRun(
            id: $record->id,
            definitionName: $record->definition_name,
            dryRun: (bool) $record->dry_run,
            startedAt: $this->immutableDate($record->started_at) ?? $this->now(),
            correlationId: $record->correlation_id,
            idempotencyKey: $record->idempotency_key,
            replayedFromRunId: $record->replayed_from_run_id,
        );
        $run->status = $record->status;
        $run->failedStep = $record->failed_step;
        $run->compensated = (bool) $record->compensated;
        $run->finishedAt = $this->immutableDate($record->finished_at);

        if ($store instanceof FlowStore) {
            foreach ($store->steps()->forRun($record->id) as $stepRecord) {
                $result = $this->flowStepResultFromRecord($stepRecord);

                if ($result instanceof FlowStepResult) {
                    $run->recordStepResult($stepRecord->step_name, $result);
                }
            }
        }

        return $run;
    }

    private function flowStepResultFromRecord(FlowStepRecord $record): ?FlowStepResult
    {
        if ($record->status === 'failed') {
            return FlowStepResult::failed(new FlowExecutionException(
                $record->error_message ?? 'Persisted flow step failed.',
            ));
        }

        if ($record->status === 'paused') {
            return FlowStepResult::paused($record->output ?? [], $record->business_impact);
        }

        if ($record->status === 'skipped' || $record->dry_run_skipped) {
            return FlowStepResult::dryRunSkipped();
        }

        if ($record->status !== 'succeeded') {
            return null;
        }

        return FlowStepResult::success($record->output ?? [], $record->business_impact);
    }

    private function immutableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return null;
    }

    private function persistRunFinished(?FlowStore $store, FlowRun $run, ?string $compensationStatus = null): void
    {
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
        ?FlowStore $store,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        DateTimeInterface $startedAt,
    ): void {
        if ($store === null) {
            return;
        }

        $store->steps()->createOrUpdate($run->id, $step->name, [
            'dry_run_skipped' => false,
            'handler' => $step->handlerFqcn,
            'input' => $this->stepInputSnapshot($context),
            'sequence' => $sequence,
            'started_at' => $startedAt,
            'status' => 'running',
        ]);
    }

    private function persistStepFinished(
        ?FlowStore $store,
        FlowRun $run,
        FlowStep $step,
        int $sequence,
        FlowContext $context,
        FlowStepResult $result,
        DateTimeInterface $startedAt,
        DateTimeInterface $finishedAt,
        ?PayloadRedactor $redactor = null,
    ): void {
        if ($store === null) {
            return;
        }

        $error = $result->error;

        $store->steps()->createOrUpdate($run->id, $step->name, [
            'business_impact' => $result->businessImpact,
            'duration_ms' => $this->durationMs($startedAt, $finishedAt),
            'dry_run_skipped' => $result->dryRunSkipped,
            'error_class' => $error instanceof Throwable ? $error::class : null,
            'error_message' => $this->safeErrorMessage($error, $redactor),
            'finished_at' => $finishedAt,
            'handler' => $step->handlerFqcn,
            'input' => $this->stepInputSnapshot($context),
            'output' => $result->success ? $result->output : null,
            'sequence' => $sequence,
            'started_at' => $startedAt,
            'status' => $this->persistedStepStatus($result),
        ]);
    }

    private function persistedStepStatus(FlowStepResult $result): string
    {
        if ($result->paused) {
            return 'paused';
        }

        if (! $result->success) {
            return 'failed';
        }

        return $result->dryRunSkipped ? 'skipped' : 'succeeded';
    }

    /**
     * @return array{flow_input: array<string, mixed>, step_output_keys: list<string>}
     */
    private function stepInputSnapshot(FlowContext $context): array
    {
        return [
            'flow_input' => $context->input,
            'step_output_keys' => array_keys($context->stepOutputs),
        ];
    }

    private function safeErrorMessage(?Throwable $error, ?PayloadRedactor $redactor = null): ?string
    {
        if (! $error instanceof Throwable) {
            return null;
        }

        return $this->redactText($error->getMessage(), $redactor);
    }

    private function redactText(string $message, ?PayloadRedactor $redactor = null): string
    {
        $message = $this->redactTextWithPayloadRedactor($message, $redactor);
        $persistence = $this->config['persistence'] ?? [];
        $redaction = is_array($persistence) ? ($persistence['redaction'] ?? []) : [];

        if (! is_array($redaction) || (bool) ($redaction['enabled'] ?? true) === false) {
            return $message;
        }

        $replacement = (string) ($redaction['replacement'] ?? '[redacted]');
        $keys = array_values(array_filter((array) ($redaction['keys'] ?? []), 'is_string'));
        $message = $this->redactBearerTokens($message, $replacement);
        $message = $this->redactConfiguredKeyValues($message, $keys, $replacement);

        foreach ($keys as $key) {
            $keyPattern = $this->redactionKeyPattern($key);
            $message = preg_replace_callback(
                '/\b('.$keyPattern.')\b(\s*[:=]\s*)(?:Bearer\s+)?([^\s,;]+)/i',
                static fn (array $matches): string => $matches[1].$matches[2].$replacement,
                $message,
            ) ?? $message;
            $message = preg_replace_callback(
                '/(["\']'.$keyPattern.'["\']\s*:\s*["\'])([^"\']+)(["\'])/i',
                static fn (array $matches): string => $matches[1].$replacement.$matches[3],
                $message,
            ) ?? $message;
        }

        return $this->redactBearerTokens($message, $replacement);
    }

    /**
     * @param  list<string>  $keys
     */
    private function redactConfiguredKeyValues(string $message, array $keys, string $replacement): string
    {
        $normalizedKeys = [];

        foreach ($keys as $key) {
            $normalizedKeys[$this->normalizeRedactionKey($key)] = true;
        }

        if ($normalizedKeys === []) {
            return $message;
        }

        return preg_replace_callback(
            '/\b([A-Za-z][A-Za-z0-9_-]*)\b(\s*[:=]\s*)(?:Bearer\s+)?([^\s,;]+)/i',
            function (array $matches) use ($normalizedKeys, $replacement): string {
                if (! isset($normalizedKeys[$this->normalizeRedactionKey((string) $matches[1])])) {
                    return (string) $matches[0];
                }

                return $matches[1].$matches[2].$replacement;
            },
            $message,
        ) ?? $message;
    }

    private function redactTextWithPayloadRedactor(string $message, ?PayloadRedactor $redactor = null): string
    {
        $redactor ??= $this->redactorForExecution();

        $redactor = PayloadRedactorResolution::current($redactor);

        $redacted = $redactor->redact([
            'error_message' => $message,
            'message' => $message,
        ]);

        foreach (['error_message', 'message'] as $key) {
            if (isset($redacted[$key]) && is_string($redacted[$key]) && $redacted[$key] !== $message) {
                return $redacted[$key];
            }
        }

        return $message;
    }

    private function redactBearerTokens(string $message, string $replacement): string
    {
        return preg_replace_callback(
            '/\bBearer\s+([A-Za-z0-9._~+\/=-]+)/i',
            static fn (): string => 'Bearer '.$replacement,
            $message,
        ) ?? $message;
    }

    private function redactionKeyPattern(string $key): string
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $parts = preg_split('/[^A-Za-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || count($parts) <= 1) {
            $characters = preg_split('//', $key, -1, PREG_SPLIT_NO_EMPTY);

            if ($characters === false || $characters === []) {
                return '(?!)';
            }

            return implode('[_\-\s]*', array_map(
                static fn (string $character): string => preg_quote($character, '/'),
                $characters,
            ));
        }

        return implode('[_\-\s]*', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            $parts,
        ));
    }

    private function normalizeRedactionKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $businessImpact
     */
    private function recordAudit(
        ?FlowStore $store,
        string $event,
        FlowRun $run,
        ?string $stepName,
        array $payload,
        ?array $businessImpact = null,
        ?DateTimeInterface $occurredAt = null,
    ): void {
        if ($store === null || ! $this->auditEnabled()) {
            return;
        }

        $store->audit()->append(
            runId: $run->id,
            event: $event,
            payload: $payload,
            stepName: $stepName,
            businessImpact: $businessImpact,
            occurredAt: $occurredAt,
        );
    }

    private function storeForExecution(bool $dryRun): ?FlowStore
    {
        $persistence = $this->config['persistence'] ?? [];

        if ($dryRun || ! is_array($persistence) || ! (bool) ($persistence['enabled'] ?? false)) {
            return null;
        }

        if ($this->store instanceof FlowStore) {
            return $this->store;
        }

        /** @var FlowStore $store */
        $store = $this->container->make(FlowStore::class);

        return $store;
    }

    private function redactorForExecution(): PayloadRedactor
    {
        $redactor = $this->redactor ?? $this->resolvePayloadRedactor();

        return PayloadRedactorResolution::current($redactor);
    }

    private function storeWithExecutionRedactor(?FlowStore $store, ?PayloadRedactor $redactor): ?FlowStore
    {
        if (! $store instanceof RedactorAwareFlowStore || ! $redactor instanceof PayloadRedactor) {
            return $store;
        }

        return $store->withPayloadRedactor($redactor);
    }

    private function resolvePayloadRedactor(): PayloadRedactor
    {
        /** @var PayloadRedactor $redactor */
        $redactor = $this->container->make(PayloadRedactor::class);

        return $redactor;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function runOutput(FlowRun $run): ?array
    {
        $output = [];

        foreach ($run->stepResults as $stepName => $result) {
            if (! $result->success || $result->dryRunSkipped || $result->paused || $stepName === $run->failedStep) {
                continue;
            }

            $output[$stepName] = $result->output;
        }

        return $output === [] ? null : $output;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function runBusinessImpact(FlowRun $run): ?array
    {
        $businessImpact = [];

        foreach ($run->stepResults as $stepName => $result) {
            if ($result->businessImpact === null || $result->paused || $stepName === $run->failedStep) {
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

    private function queueLockStore(): ?string
    {
        $store = $this->config['queue']['lock_store'] ?? null;

        if (is_string($store) && $store !== '') {
            return $store;
        }

        $defaultStore = $this->container->make('config')->get('cache.default');

        return is_string($defaultStore) && $defaultStore !== '' ? $defaultStore : null;
    }

    private function queueLockSeconds(): int
    {
        $seconds = $this->config['queue']['lock_seconds'] ?? 3600;

        return is_int($seconds) && $seconds >= 1 ? $seconds : 3600;
    }

    private function queueLockRetrySeconds(): int
    {
        $seconds = $this->config['queue']['lock_retry_seconds'] ?? 30;

        return is_int($seconds) && $seconds >= 1 ? $seconds : 30;
    }

    private function queueRetryPolicy(): QueueRetryPolicy
    {
        $queue = $this->config['queue'] ?? [];

        return QueueRetryPolicy::fromConfig(is_array($queue) ? $queue : []);
    }

    private function assertQueuedRunRetryPolicyIsSafe(QueueRetryPolicy $retryPolicy, ?FlowExecutionOptions $options): void
    {
        if (! $retryPolicy->canRetryWholeRun()) {
            return;
        }

        if ($this->queueDefaultConnectionUsesSyncDriver()) {
            return;
        }

        throw new FlowExecutionException(
            'Async queued run retries can re-run the whole flow. Leave queue.tries as null/1 and queue.backoff_seconds as null until step-level retries or replay are available.',
        );
    }

    private function queueDefaultConnectionUsesSyncDriver(): bool
    {
        $config = $this->container->make('config');
        $connection = $config->get('queue.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return $config->get('queue.connections.'.$connection.'.driver') === 'sync';
    }

    private function dispatchEvent(object $event): void
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
        if (is_callable($this->clock)) {
            /** @var DateTimeImmutable $now */
            $now = ($this->clock)();

            return $now;
        }

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
