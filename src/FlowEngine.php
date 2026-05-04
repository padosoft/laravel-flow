<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
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
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
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

    private const APPROVAL_DECISION_LOCK_PREFIX = 'laravel-flow:approval-run:';

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
        private readonly ?ApprovalTokenManager $approvalTokenManager = null,
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
     * Resume a persisted approval gate after approving its one-time token.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    public function resume(string $token, array $payload = [], array $actor = []): FlowRun
    {
        return $this->decideApproval($token, FlowApprovalRecord::STATUS_APPROVED, $payload, $actor);
    }

    /**
     * Reject a persisted approval gate and compensate prior completed steps.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    public function reject(string $token, array $payload = [], array $actor = []): FlowRun
    {
        return $this->decideApproval($token, FlowApprovalRecord::STATUS_REJECTED, $payload, $actor);
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

        return $this->executeFromIndex($definition, $run, $context, [], 0, 0, $store, $redactor);
    }

    /**
     * @param  list<FlowStep>  $completedSteps
     */
    private function executeFromIndex(
        FlowDefinition $definition,
        FlowRun $run,
        FlowContext $context,
        array $completedSteps,
        int $startIndex,
        int $sequence,
        ?FlowStore $store,
        ?PayloadRedactor $redactor,
    ): FlowRun {
        $dryRun = $context->dryRun;

        for ($index = $startIndex; $index < count($definition->steps); $index++) {
            $step = $definition->steps[$index];
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
                $issuedApprovalToken = null;

                try {
                    $this->persistAtomically($store, function () use (
                        $store,
                        $run,
                        $step,
                        $sequence,
                        $context,
                        &$result,
                        &$issuedApprovalToken,
                        $stepStartedAt,
                        $stepFinishedAt,
                        $definition,
                        $dryRun,
                        $redactor,
                    ): void {
                        $issuedApprovalToken = $this->issueApprovalTokenForPausedStep($store, $run, $step);

                        if ($issuedApprovalToken instanceof IssuedApprovalToken) {
                            $result = $this->pausedResultWithApprovalToken($result, $issuedApprovalToken);
                            $run->recordStepResult($step->name, $result);
                        }

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

                    if ($issuedApprovalToken instanceof IssuedApprovalToken) {
                        $run->recordApprovalToken($issuedApprovalToken);
                    }

                    $this->dispatchOrCaptureListenerFailure(
                        new FlowPaused($run->id, $definition->name, $step->name, $result, $dryRun),
                        $listenerFailureEvent,
                    );
                } catch (Throwable $e) {
                    $this->expireApprovalTokenBestEffort($issuedApprovalToken, $stepFinishedAt);
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
                $this->recordFailedStepAndCompensate(
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
                );

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

    /**
     * @param  list<FlowStep>  $completedSteps
     */
    private function recordFailedStepAndCompensate(
        FlowDefinition $definition,
        FlowContext $context,
        array $completedSteps,
        FlowRun $run,
        ?FlowStore $store,
        FlowStep $step,
        int $sequence,
        FlowStepResult $result,
        DateTimeInterface $stepStartedAt,
        DateTimeInterface $stepFinishedAt,
        ?PayloadRedactor $redactor,
    ): void {
        $error = $result->error;
        $run->markFailed($step->name, $this->immutableDate($stepFinishedAt) ?? $this->now());
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
                    'dry_run' => $context->dryRun,
                    'error_class' => $error instanceof Throwable ? $error::class : null,
                    'error_message' => $this->safeErrorMessage($error, $redactor),
                    'status' => 'failed',
                ], occurredAt: $stepFinishedAt);
                $this->persistRunFinished($store, $run);
            });
            $this->dispatchOrCaptureListenerFailure(
                new FlowStepFailed($run->id, $definition->name, $step->name, $result, $context->dryRun),
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
                $this->immutableDate($stepFinishedAt) ?? $this->now(),
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
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    private function decideApproval(string $token, string $decision, array $payload, array $actor): FlowRun
    {
        $token = trim($token);

        if ($token === '') {
            throw new FlowInputException('Approval token must not be blank.');
        }

        [$store, $redactor] = $this->approvalDecisionStore();
        $approval = $this->approvalDecisionRecord($token);

        return $this->withApprovalDecisionLock($token, $decision, $approval, $store, function () use ($token, $decision, $payload, $actor, $store, $redactor): FlowRun {
            return $this->decideApprovalWithLock($token, $decision, $payload, $actor, $store, $redactor);
        });
    }

    private function approvalDecisionRecord(string $token): FlowApprovalRecord
    {
        try {
            $approval = $this->approvalTokenManager()->find($token);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }

        if (! ($approval instanceof FlowApprovalRecord)
            || $approval->status === FlowApprovalRecord::STATUS_EXPIRED
        ) {
            throw new FlowExecutionException('Approval token is invalid or expired.');
        }

        return $approval;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    private function decideApprovalWithLock(
        string $token,
        string $decision,
        array $payload,
        array $actor,
        FlowStore $store,
        PayloadRedactor $redactor,
    ): FlowRun {
        $preDecisionApproval = $this->approvalDecisionRecord($token);

        $preDecisionState = null;

        if ($preDecisionApproval->status === FlowApprovalRecord::STATUS_PENDING) {
            $preDecisionRunRecord = $this->approvalRunRecord($preDecisionApproval, $store);

            if ($preDecisionRunRecord->status !== FlowRun::STATUS_PAUSED) {
                return $this->flowRunFromRecord($preDecisionRunRecord, $store);
            }

            $preDecisionState = $this->approvalExecutionState($preDecisionApproval, $store, $preDecisionRunRecord);
            $this->conditionalRuns($store);
        }

        [$approval, $consumedNow] = $this->consumeApprovalDecisionForPausedRun($token, $decision, $payload, $actor, $redactor);
        $decisionPayload = $approval->payload ?? [];
        $decisionActor = $approval->actor ?? [];

        if ($decision === FlowApprovalRecord::STATUS_APPROVED) {
            return $this->resumeApprovedApproval($approval, $consumedNow, $decisionPayload, $decisionActor, $store, $redactor, $preDecisionState);
        }

        return $this->rejectApprovalDecision($approval, $decisionPayload, $decisionActor, $store, $redactor, $preDecisionState);
    }

    /**
     * @param  callable(): FlowRun  $callback
     */
    private function withApprovalDecisionLock(
        string $token,
        string $decision,
        FlowApprovalRecord $approval,
        FlowStore $store,
        callable $callback,
    ): FlowRun {
        /** @var CacheFactory $cache */
        $cache = $this->container->make(CacheFactory::class);
        $lockStoreName = $this->queueLockStore();

        try {
            $repository = $cache->store($lockStoreName);
        } catch (InvalidArgumentException $e) {
            throw new FlowExecutionException(sprintf(
                'Approval resume/reject requires a configured cache lock store; cache store [%s] is not defined.',
                $lockStoreName ?? 'default',
            ), previous: $e);
        }

        $cacheStore = $repository->getStore();

        if ($cacheStore instanceof ArrayStore) {
            throw new FlowExecutionException('Approval resume/reject requires a shared cache lock store; the array store is process-local.');
        }

        if (! $cacheStore instanceof LockProvider) {
            throw new FlowExecutionException('Approval resume/reject requires a cache store that supports atomic locks.');
        }

        $lock = $cacheStore->lock(
            self::APPROVAL_DECISION_LOCK_PREFIX.$approval->run_id,
            $this->queueLockSeconds(),
        );

        if (! $lock->get()) {
            return $this->approvalRunStateForToken($token, $decision, $store);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function approvalRunStateForToken(string $token, string $decision, FlowStore $store): FlowRun
    {
        $approval = $this->approvalDecisionRecord($token);

        if ($approval->status === FlowApprovalRecord::STATUS_PENDING) {
            throw new FlowExecutionException('Approval token could not be consumed. Try again.');
        }

        if (in_array($approval->status, [FlowApprovalRecord::STATUS_APPROVED, FlowApprovalRecord::STATUS_REJECTED], true)
            && $approval->status !== $decision
        ) {
            throw new FlowExecutionException(sprintf(
                'Approval token was already decided as [%s].',
                $approval->status,
            ));
        }

        $runRecord = $this->approvalRunRecord($approval, $store);

        if ($runRecord->status === FlowRun::STATUS_PAUSED
            && ! $this->hasPersistedApprovalDecisionStep($approval, $store, $runRecord)
        ) {
            throw new FlowExecutionException('Approval token could not be consumed. Try again.');
        }

        return $this->flowRunFromRecord($runRecord, $store);
    }

    private function hasPersistedApprovalDecisionStep(
        FlowApprovalRecord $approval,
        FlowStore $store,
        FlowRunRecord $runRecord,
    ): bool {
        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            if ($stepRecord->step_name !== $approval->step_name) {
                continue;
            }

            return match ($approval->status) {
                FlowApprovalRecord::STATUS_APPROVED => $stepRecord->status === 'succeeded',
                FlowApprovalRecord::STATUS_REJECTED => $stepRecord->status === 'failed',
                default => false,
            };
        }

        return false;
    }

    private function hasPersistedDownstreamPause(
        FlowApprovalRecord $approval,
        FlowStore $store,
        FlowRunRecord $runRecord,
    ): bool {
        if ($runRecord->status !== FlowRun::STATUS_PAUSED
            || $approval->status !== FlowApprovalRecord::STATUS_APPROVED
        ) {
            return false;
        }

        $approvalSequence = null;

        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            if ($stepRecord->step_name === $approval->step_name) {
                if ($stepRecord->status !== 'succeeded') {
                    return false;
                }

                $approvalSequence = $stepRecord->sequence;

                continue;
            }

            if ($approvalSequence !== null
                && $stepRecord->sequence > $approvalSequence
                && $stepRecord->status === 'paused'
            ) {
                return true;
            }
        }

        return false;
    }

    private function persistedDownstreamPausedApprovalGate(
        FlowApprovalRecord $approval,
        FlowStore $store,
        FlowRunRecord $runRecord,
    ): ?FlowStepRecord {
        if ($runRecord->status !== FlowRun::STATUS_PAUSED
            || $approval->status !== FlowApprovalRecord::STATUS_APPROVED
        ) {
            return null;
        }

        $approvalSequence = null;

        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            if ($stepRecord->step_name === $approval->step_name) {
                if ($stepRecord->status !== 'succeeded') {
                    return null;
                }

                $approvalSequence = $stepRecord->sequence;

                continue;
            }

            if ($approvalSequence !== null
                && $stepRecord->sequence > $approvalSequence
                && $stepRecord->status === 'paused'
                && $stepRecord->handler === ApprovalGate::class
            ) {
                return $stepRecord;
            }
        }

        return null;
    }

    private function flowRunFromRecordWithReissuedApprovalToken(
        FlowRunRecord $runRecord,
        FlowStore $store,
        FlowStepRecord $approvalStepRecord,
    ): FlowRun {
        $run = $this->flowRunFromRecord($runRecord, $store);

        try {
            $token = $this->approvalTokenManager()->reissuePendingForStep($runRecord->id, $approvalStepRecord->step_name);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }

        if ($token instanceof IssuedApprovalToken) {
            $output = is_array($approvalStepRecord->output) ? $approvalStepRecord->output : [];
            $output['approval_expires_at'] = $token->expiresAt->format(DateTimeInterface::ATOM);

            $refreshedStep = $store->steps()->createOrUpdate($runRecord->id, $approvalStepRecord->step_name, [
                'business_impact' => $approvalStepRecord->business_impact,
                'dry_run_skipped' => (bool) $approvalStepRecord->dry_run_skipped,
                'duration_ms' => $approvalStepRecord->duration_ms,
                'error_class' => $approvalStepRecord->error_class,
                'error_message' => $approvalStepRecord->error_message,
                'finished_at' => $approvalStepRecord->finished_at,
                'handler' => $approvalStepRecord->handler,
                'input' => $approvalStepRecord->input,
                'output' => $output,
                'sequence' => $approvalStepRecord->sequence,
                'started_at' => $approvalStepRecord->started_at,
                'status' => $approvalStepRecord->status,
            ]);
            $result = $this->flowStepResultFromRecord($refreshedStep);

            if ($result instanceof FlowStepResult) {
                $run->recordStepResult($refreshedStep->step_name, $result);
            }

            $run->recordApprovalToken($token);
        }

        return $run;
    }

    private function hasPersistedDownstreamApprovalGate(
        FlowApprovalRecord $approval,
        FlowStore $store,
        FlowRunRecord $runRecord,
    ): bool {
        if ($approval->status !== FlowApprovalRecord::STATUS_APPROVED) {
            return false;
        }

        $approvalSequence = null;

        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            if ($stepRecord->step_name === $approval->step_name) {
                if ($stepRecord->status !== 'succeeded') {
                    return false;
                }

                $approvalSequence = $stepRecord->sequence;

                continue;
            }

            if ($approvalSequence !== null
                && $stepRecord->sequence > $approvalSequence
                && $stepRecord->handler === ApprovalGate::class
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: FlowStore, 1: PayloadRedactor}
     */
    private function approvalDecisionStore(): array
    {
        $store = $this->storeForExecution(false);

        if (! $store instanceof FlowStore) {
            throw new FlowExecutionException('Approval resume/reject requires persistence to be enabled.');
        }

        $redactor = $this->redactorForExecution();

        return [$this->storeWithExecutionRedactor($store, $redactor), $redactor];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     * @return array{0: FlowApprovalRecord, 1: bool}
     */
    private function consumeApprovalDecisionForPausedRun(
        string $token,
        string $decision,
        array $payload,
        array $actor,
        PayloadRedactor $redactor,
    ): array {
        $manager = $this->approvalTokenManager($redactor);

        try {
            $approval = $decision === FlowApprovalRecord::STATUS_APPROVED
                ? $manager->approveForRunStatus($token, FlowRun::STATUS_PAUSED, $actor, $payload)
                : $manager->rejectForRunStatus($token, FlowRun::STATUS_PAUSED, $actor, $payload);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }

        if ($approval instanceof FlowApprovalRecord) {
            return [$approval, true];
        }

        try {
            $approval = $manager->find($token);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }

        if (! $approval instanceof FlowApprovalRecord || $approval->status === FlowApprovalRecord::STATUS_EXPIRED) {
            throw new FlowExecutionException('Approval token is invalid or expired.');
        }

        if ($approval->status === FlowApprovalRecord::STATUS_PENDING) {
            throw new FlowExecutionException('Approval token could not be consumed. Try again.');
        }

        if ($approval->status !== $decision) {
            throw new FlowExecutionException(sprintf(
                'Approval token was already decided as [%s].',
                $approval->status,
            ));
        }

        return [$approval, false];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    private function resumeApprovedApproval(
        FlowApprovalRecord $approval,
        bool $consumedNow,
        array $payload,
        array $actor,
        FlowStore $store,
        PayloadRedactor $redactor,
        ?ApprovalRecoveryState $preDecisionState = null,
    ): FlowRun {
        $runRecord = $this->approvalRunRecord($approval, $store);

        if (! in_array($runRecord->status, [FlowRun::STATUS_PAUSED, FlowRun::STATUS_RUNNING], true)) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        if (! $consumedNow) {
            $downstreamPausedApprovalGate = $this->persistedDownstreamPausedApprovalGate($approval, $store, $runRecord);

            if ($downstreamPausedApprovalGate instanceof FlowStepRecord) {
                return $this->flowRunFromRecordWithReissuedApprovalToken($runRecord, $store, $downstreamPausedApprovalGate);
            }
        }

        if (! $consumedNow && $this->hasPersistedDownstreamApprovalGate($approval, $store, $runRecord)) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        if (! $consumedNow && $this->hasPersistedDownstreamPause($approval, $store, $runRecord)) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        $state = $consumedNow && $preDecisionState !== null
            ? $preDecisionState
            : $this->approvalExecutionState($approval, $store, $runRecord);

        if ($runRecord->status !== FlowRun::STATUS_PAUSED) {
            if ($state->approvalStepRecord->status === 'succeeded' && ! $state->wouldExecuteHandlers()) {
                $run = $state->run;
                $run->markRunning();

                return $this->executeFromIndex(
                    $state->definition,
                    $run,
                    $state->retryContext,
                    $state->retryCompletedSteps,
                    $state->retryStartIndex,
                    $state->retrySequence,
                    $store,
                    $redactor,
                );
            }

            return $this->flowRunFromRecord($runRecord, $store);
        }

        $approvalStepRecord = $state->approvalStepRecord;

        if ($state->pausedDownstreamStep !== null) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        if ($approvalStepRecord->status === 'succeeded') {
            $run = $state->run;
            $claimedRunRecord = null;

            $this->persistAtomically($store, function () use ($store, $run, &$claimedRunRecord): void {
                $claimedRunRecord = $this->conditionalRuns($store)->updateWhereStatus($run->id, FlowRun::STATUS_PAUSED, [
                    'duration_ms' => null,
                    'finished_at' => null,
                    'status' => FlowRun::STATUS_RUNNING,
                ]);
            });

            if (! $claimedRunRecord instanceof FlowRunRecord) {
                $currentRunRecord = $store->runs()->find($run->id);

                if ($currentRunRecord instanceof FlowRunRecord) {
                    return $this->flowRunFromRecord($currentRunRecord, $store);
                }

                throw new FlowExecutionException(sprintf('Flow run [%s] was not found while claiming approval resume.', $run->id));
            }

            $run->markRunning();

            return $this->executeFromIndex(
                $state->definition,
                $run,
                $state->retryContext,
                $state->retryCompletedSteps,
                $state->retryStartIndex,
                $state->retrySequence,
                $store,
                $redactor,
            );
        }

        if ($approvalStepRecord->status !== 'paused') {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        $run = $state->run;
        $result = $this->approvedApprovalResult($approval, $payload, $actor);
        $contextAfterStep = $state->context->withStepOutput($approval->step_name, $result->output);
        $completedSteps = [...$state->completedSteps, $state->approvalStep];
        $finishedAt = $this->immutableDate($approval->decided_at) ?? $this->now();
        $startedAt = $this->immutableDate($approvalStepRecord->started_at) ?? $finishedAt;
        $listenerFailureEvent = null;
        $claimedRunRecord = null;

        try {
            $this->persistAtomically($store, function () use (
                $store,
                $run,
                $approval,
                $result,
                $state,
                $startedAt,
                $finishedAt,
                $redactor,
                &$claimedRunRecord,
            ): void {
                $claimedRunRecord = $this->conditionalRuns($store)->updateWhereStatus($run->id, FlowRun::STATUS_PAUSED, [
                    'duration_ms' => null,
                    'finished_at' => null,
                    'status' => FlowRun::STATUS_RUNNING,
                ]);

                if (! $claimedRunRecord instanceof FlowRunRecord) {
                    return;
                }

                $run->markRunning();
                $run->recordStepResult($approval->step_name, $result);

                $this->persistStepFinished(
                    $store,
                    $run,
                    $state->approvalStep,
                    $state->approvalStepRecord->sequence,
                    $state->context,
                    $result,
                    $startedAt,
                    $finishedAt,
                    $redactor,
                );
                $this->recordAudit($store, 'FlowStepCompleted', $run, $approval->step_name, [
                    'approval_id' => $approval->id,
                    'approval_status' => FlowApprovalRecord::STATUS_APPROVED,
                    'definition_name' => $state->definition->name,
                    'dry_run' => false,
                    'output' => $result->output,
                    'status' => 'succeeded',
                ], occurredAt: $finishedAt);
                $this->persistRunFinished($store, $run);
            });

            if (! $claimedRunRecord instanceof FlowRunRecord) {
                $currentRunRecord = $store->runs()->find($run->id);

                if ($currentRunRecord instanceof FlowRunRecord) {
                    return $this->flowRunFromRecord($currentRunRecord, $store);
                }

                throw new FlowExecutionException(sprintf('Flow run [%s] was not found while claiming approval resume.', $run->id));
            }

            $this->dispatchOrCaptureListenerFailure(
                new FlowStepCompleted($run->id, $state->definition->name, $approval->step_name, $result, false),
                $listenerFailureEvent,
            );
        } catch (Throwable $e) {
            $this->compensateAfterRuntimeAbort(
                $state->definition,
                $contextAfterStep,
                $completedSteps,
                $run,
                $store,
                $state->approvalStep,
                $state->approvalStepRecord->sequence,
                FlowStepResult::failed($e),
                $startedAt,
                $this->now(),
                $redactor,
                listenerEvent: $listenerFailureEvent,
                failedStepPersistenceContext: $state->context,
            );

            throw $e;
        }

        return $this->executeFromIndex(
            $state->definition,
            $run,
            $contextAfterStep,
            $completedSteps,
            $state->approvalIndex + 1,
            $state->approvalStepRecord->sequence,
            $store,
            $redactor,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    private function rejectApprovalDecision(
        FlowApprovalRecord $approval,
        array $payload,
        array $actor,
        FlowStore $store,
        PayloadRedactor $redactor,
        ?ApprovalRecoveryState $preDecisionState = null,
    ): FlowRun {
        $runRecord = $this->approvalRunRecord($approval, $store);

        if ($runRecord->status !== FlowRun::STATUS_PAUSED) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        $state = $preDecisionState ?? $this->approvalExecutionState($approval, $store, $runRecord);

        if ($state->approvalStepRecord->status !== 'paused') {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        $run = $state->run;
        $result = FlowStepResult::failed(new FlowExecutionException(sprintf(
            'Approval step [%s] was rejected.',
            $approval->step_name,
        )));
        $finishedAt = $this->immutableDate($approval->decided_at) ?? $this->now();

        return $this->recordRejectedApprovalStepAndCompensate($approval, $state, $run, $store, $result, $finishedAt, $redactor);
    }

    private function recordRejectedApprovalStepAndCompensate(
        FlowApprovalRecord $approval,
        ApprovalRecoveryState $state,
        FlowRun $run,
        FlowStore $store,
        FlowStepResult $result,
        DateTimeImmutable $finishedAt,
        PayloadRedactor $redactor,
    ): FlowRun {
        $startedAt = $this->immutableDate($state->approvalStepRecord->started_at) ?? $finishedAt;
        $listenerFailureEvent = null;
        $claimedRunRecord = null;

        try {
            $this->persistAtomically($store, function () use (
                $store,
                $run,
                $state,
                $result,
                $startedAt,
                $finishedAt,
                $redactor,
                &$claimedRunRecord,
            ): void {
                $claimedRunRecord = $this->conditionalRuns($store)->updateWhereStatus($run->id, FlowRun::STATUS_PAUSED, [
                    'duration_ms' => null,
                    'finished_at' => null,
                    'status' => FlowRun::STATUS_RUNNING,
                ]);

                if (! $claimedRunRecord instanceof FlowRunRecord) {
                    return;
                }

                $run->markRunning();
                $run->recordStepResult($state->approvalStep->name, $result);
                $run->markFailed($state->approvalStep->name, $finishedAt);

                $this->persistStepFinished(
                    $store,
                    $run,
                    $state->approvalStep,
                    $state->approvalStepRecord->sequence,
                    $state->context,
                    $result,
                    $startedAt,
                    $finishedAt,
                    $redactor,
                );

                $error = $result->error;
                $this->recordAudit($store, 'FlowStepFailed', $run, $state->approvalStep->name, [
                    'definition_name' => $state->definition->name,
                    'dry_run' => false,
                    'error_class' => $error instanceof Throwable ? $error::class : null,
                    'error_message' => $this->safeErrorMessage($error, $redactor),
                    'status' => 'failed',
                ], occurredAt: $finishedAt);
                $this->persistRunFinished($store, $run);
            });

            if (! $claimedRunRecord instanceof FlowRunRecord) {
                $currentRunRecord = $store->runs()->find($run->id);

                if ($currentRunRecord instanceof FlowRunRecord) {
                    return $this->flowRunFromRecord($currentRunRecord, $store);
                }

                throw new FlowExecutionException(sprintf('Flow run [%s] was not found while claiming approval reject.', $run->id));
            }

            $this->dispatchOrCaptureListenerFailure(
                new FlowStepFailed($run->id, $state->definition->name, $state->approvalStep->name, $result, false),
                $listenerFailureEvent,
            );
        } catch (Throwable $e) {
            $this->compensateAfterRuntimeAbort(
                $state->definition,
                $state->context,
                $state->completedSteps,
                $run,
                $store,
                $state->approvalStep,
                $state->approvalStepRecord->sequence,
                $result,
                $startedAt,
                $finishedAt,
                $redactor,
                listenerEvent: $listenerFailureEvent,
            );

            throw $e;
        }

        try {
            $this->compensate($state->definition, $state->context, $state->completedSteps, $run, $store);
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    private function approvedApprovalResult(
        FlowApprovalRecord $approval,
        array $payload,
        array $actor,
    ): FlowStepResult {
        $output = [
            'approval_id' => $approval->id,
            'approval_status' => FlowApprovalRecord::STATUS_APPROVED,
        ];

        if ($payload !== []) {
            $output['approval_payload'] = $payload;
        }

        if ($actor !== []) {
            $output['approval_actor'] = $actor;
        }

        $decidedAt = $this->immutableDate($approval->decided_at);

        if ($decidedAt instanceof DateTimeImmutable) {
            $output['approval_decided_at'] = $decidedAt->format(DateTimeInterface::ATOM);
        }

        return FlowStepResult::success($output);
    }

    private function conditionalRuns(FlowStore $store): ConditionalRunRepository
    {
        $runs = $store->runs();

        if (! $runs instanceof ConditionalRunRepository) {
            throw new FlowExecutionException(sprintf(
                'Approval resume/reject requires the run repository to implement %s.',
                ConditionalRunRepository::class,
            ));
        }

        return $runs;
    }

    private function approvalExecutionState(
        FlowApprovalRecord $approval,
        FlowStore $store,
        ?FlowRunRecord $runRecord = null,
    ): ApprovalRecoveryState {
        $runRecord ??= $this->approvalRunRecord($approval, $store);

        $definition = $this->definition($runRecord->definition_name);
        $approvalIndex = $this->approvalStepIndex($definition, $approval->step_name);
        $approvalStep = $definition->steps[$approvalIndex];
        $run = $this->flowRunShellFromRecord($runRecord);
        $input = is_array($runRecord->input) ? $runRecord->input : [];
        $context = new FlowContext($runRecord->id, $definition->name, $input, [], (bool) $runRecord->dry_run);
        $completedSteps = [];
        $approvalStepRecord = null;
        $expectedIndex = 0;
        $retryCompletedSteps = [];
        $retryContext = $context;
        $retrySequence = max(0, $approvalIndex);
        $retryStartIndex = $approvalIndex;
        $pausedDownstreamStep = null;

        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            $result = $this->flowStepResultFromRecord($stepRecord);

            if ($result instanceof FlowStepResult) {
                $run->recordStepResult($stepRecord->step_name, $result);
            }

            $stepIndex = $this->stepIndex($definition, $stepRecord->step_name);

            if ($stepIndex === null || $stepIndex !== $expectedIndex) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because persisted step [%s] does not match the current flow definition.',
                    $stepRecord->step_name,
                ));
            }

            if ($stepRecord->handler !== $definition->steps[$stepIndex]->handlerFqcn) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because persisted step [%s] does not match the current flow definition.',
                    $stepRecord->step_name,
                ));
            }

            if ($stepIndex < $approvalIndex && ! in_array($stepRecord->status, ['succeeded', 'skipped'], true)) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because prior step [%s] is [%s].',
                    $stepRecord->step_name,
                    $stepRecord->status,
                ));
            }

            if ($stepIndex < $approvalIndex
                && $stepRecord->status === 'succeeded'
                && ! $stepRecord->dry_run_skipped
            ) {
                $context = $context->withStepOutput($stepRecord->step_name, $stepRecord->output ?? []);
            }

            if ($stepIndex < $approvalIndex) {
                $completedSteps[] = $definition->steps[$stepIndex];
                $retryCompletedSteps = $completedSteps;
                $retryContext = $context;
                $retrySequence = $stepRecord->sequence;
                $retryStartIndex = $stepIndex + 1;
                $expectedIndex++;

                continue;
            }

            if ($stepIndex === $approvalIndex) {
                $approvalStepRecord = $stepRecord;

                if ($stepRecord->status === 'succeeded') {
                    $retryContext = $context->withStepOutput($stepRecord->step_name, $stepRecord->output ?? []);
                    $retryCompletedSteps = [...$completedSteps, $approvalStep];
                    $retrySequence = $stepRecord->sequence;
                    $retryStartIndex = $approvalIndex + 1;
                } elseif (! in_array($stepRecord->status, ['paused', 'failed'], true)) {
                    throw new FlowExecutionException(sprintf(
                        'Cannot resume approval because approval step [%s] is [%s].',
                        $stepRecord->step_name,
                        $stepRecord->status,
                    ));
                }

                $expectedIndex++;

                continue;
            }

            if (! $approvalStepRecord instanceof FlowStepRecord || $approvalStepRecord->status !== 'succeeded') {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because downstream step [%s] was persisted before the approval gate succeeded.',
                    $stepRecord->step_name,
                ));
            }

            if ($stepRecord->status === 'paused' && $runRecord->status === FlowRun::STATUS_PAUSED) {
                $pausedDownstreamStep = $stepRecord->step_name;
                $expectedIndex++;

                continue;
            }

            if ($stepRecord->status === 'running' && $runRecord->status === FlowRun::STATUS_RUNNING) {
                $retrySequence = max(0, $stepRecord->sequence - 1);
                $retryStartIndex = $stepIndex;
                $expectedIndex++;

                continue;
            }

            if (! in_array($stepRecord->status, ['succeeded', 'skipped'], true)) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because downstream step [%s] is [%s].',
                    $stepRecord->step_name,
                    $stepRecord->status,
                ));
            }

            if ($stepRecord->status === 'succeeded' && ! $stepRecord->dry_run_skipped) {
                $retryContext = $retryContext->withStepOutput($stepRecord->step_name, $stepRecord->output ?? []);
            }

            $retryCompletedSteps[] = $definition->steps[$stepIndex];
            $retrySequence = $stepRecord->sequence;
            $retryStartIndex = $stepIndex + 1;
            $expectedIndex++;
        }

        if (! $approvalStepRecord instanceof FlowStepRecord) {
            throw new FlowExecutionException(sprintf(
                'Cannot resume approval because step [%s] was not persisted for run [%s].',
                $approval->step_name,
                $runRecord->id,
            ));
        }

        return new ApprovalRecoveryState(
            approvalIndex: $approvalIndex,
            approvalStep: $approvalStep,
            approvalStepRecord: $approvalStepRecord,
            completedSteps: $completedSteps,
            context: $context,
            definition: $definition,
            pausedDownstreamStep: $pausedDownstreamStep,
            retryCompletedSteps: $retryCompletedSteps,
            retryContext: $retryContext,
            retrySequence: $retrySequence,
            retryStartIndex: $retryStartIndex,
            run: $run,
            runRecord: $runRecord,
        );
    }

    private function approvalRunRecord(FlowApprovalRecord $approval, FlowStore $store): FlowRunRecord
    {
        try {
            $runRecord = $store->runs()->find($approval->run_id);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }

        if (! $runRecord instanceof FlowRunRecord) {
            throw new FlowExecutionException(sprintf('Approval run [%s] was not found.', $approval->run_id));
        }

        return $runRecord;
    }

    private function approvalPersistenceUnavailableException(QueryException $e): FlowExecutionException
    {
        return new FlowExecutionException(
            'Approval resume/reject requires published laravel-flow persistence tables and a reachable persistence connection. Run the package migrations and verify the persistence connection.',
            previous: $e,
        );
    }

    /**
     * @return iterable<FlowStepRecord>
     */
    private function stepRecordsForRun(FlowStore $store, string $runId): iterable
    {
        try {
            return $store->steps()->forRun($runId);
        } catch (QueryException $e) {
            throw $this->flowPersistenceUnavailableException($e);
        }
    }

    private function flowPersistenceUnavailableException(QueryException $e): FlowExecutionException
    {
        return new FlowExecutionException(
            'Laravel Flow persistence requires published laravel-flow persistence tables and a reachable persistence connection. Run the package migrations and verify the persistence connection.',
            previous: $e,
        );
    }

    private function approvalStepIndex(FlowDefinition $definition, string $stepName): int
    {
        $index = $this->stepIndex($definition, $stepName);

        if ($index === null) {
            throw new FlowExecutionException(sprintf(
                'Approval step [%s] does not exist in current flow definition [%s].',
                $stepName,
                $definition->name,
            ));
        }

        $step = $definition->steps[$index];

        if ($step->handlerFqcn !== ApprovalGate::class) {
            throw new FlowExecutionException(sprintf(
                'Step [%s] in flow definition [%s] is not an approval gate.',
                $stepName,
                $definition->name,
            ));
        }

        return $index;
    }

    private function stepIndex(FlowDefinition $definition, string $stepName): ?int
    {
        foreach ($definition->steps as $index => $step) {
            if ($step->name === $stepName) {
                return $index;
            }
        }

        return null;
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
        $run = $this->flowRunShellFromRecord($record);

        if ($store instanceof FlowStore) {
            foreach ($this->stepRecordsForRun($store, $record->id) as $stepRecord) {
                $result = $this->flowStepResultFromRecord($stepRecord);

                if ($result instanceof FlowStepResult) {
                    $run->recordStepResult($stepRecord->step_name, $result);
                }
            }
        }

        return $run;
    }

    private function flowRunShellFromRecord(FlowRunRecord $record): FlowRun
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

    private function issueApprovalTokenForPausedStep(?FlowStore $store, FlowRun $run, FlowStep $step): ?IssuedApprovalToken
    {
        if (! ($store instanceof FlowStore) || $step->handlerFqcn !== ApprovalGate::class) {
            return null;
        }

        return $this->approvalTokenManager()->issue($run->id, $step->name, [
            'definition_name' => $run->definitionName,
            'step_name' => $step->name,
        ]);
    }

    private function pausedResultWithApprovalToken(
        FlowStepResult $result,
        IssuedApprovalToken $token,
    ): FlowStepResult {
        return FlowStepResult::paused(array_merge($result->output, [
            'approval_expires_at' => $token->expiresAt->format(DateTimeInterface::ATOM),
            'approval_id' => $token->approvalId,
        ]), $result->businessImpact);
    }

    private function expireApprovalTokenBestEffort(?IssuedApprovalToken $token, DateTimeInterface $decidedAt): void
    {
        if (! $token instanceof IssuedApprovalToken) {
            return;
        }

        try {
            $this->approvalTokenManager()->expireIssued($token, $decidedAt);
        } catch (Throwable) {
            // Preserve the pause transition exception while preventing stale pending tokens when possible.
        }
    }

    private function approvalTokenManager(?PayloadRedactor $redactor = null): ApprovalTokenManager
    {
        if ($this->approvalTokenManager instanceof ApprovalTokenManager) {
            return $redactor instanceof PayloadRedactor
                ? $this->approvalTokenManager->withPayloadRedactor($redactor)
                : $this->approvalTokenManager;
        }

        if ($redactor instanceof PayloadRedactor) {
            return $this->approvalTokenManager()->withPayloadRedactor($redactor);
        }

        /** @var ApprovalTokenManager $manager */
        $manager = $this->container->make(ApprovalTokenManager::class);

        return $manager;
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
