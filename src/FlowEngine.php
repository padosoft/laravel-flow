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
use JsonException;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore;
use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Events\FlowPaused;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\Exceptions\ApprovalPersistenceException;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\Executor\GraphApprovalCoordinator;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\GraphRunResult;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Jobs\RunFlowJob;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Persistence\EloquentWebhookOutboxRepository;
use Padosoft\LaravelFlow\Persistence\ErrorMessageRedactor;
use Padosoft\LaravelFlow\Persistence\PayloadRedactorResolution;
use Padosoft\LaravelFlow\Queue\QueueRetryPolicy;
use Throwable;

/**
 * Main entry point for laravel-flow.
 *
 * Holds the registry of {@see FlowDefinition}s and exposes execute /
 * dryRun / dispatch. Definitions stay in-memory; v0.2 can optionally
 * persist runtime runs, steps, and audit records when configured.
 *
 * @api
 */
class FlowEngine
{
    /** Bounded retries for cancel()'s abort CAS against a flapping non-terminal status. */
    private const CANCEL_MAX_ATTEMPTS = 5;

    private const COMPENSATION_STRATEGY_REVERSE_ORDER = 'reverse-order';

    private const COMPENSATION_STRATEGY_PARALLEL = 'parallel';

    private const APPROVAL_DECISION_LOCK_PREFIX = 'laravel-flow:approval-run:';

    /**
     * @var array<string, FlowDefinition>
     */
    private array $definitions = [];

    /**
     * Version+checksum pin recorded when `persist_registered` matched or
     * produced a `flow_definitions` version for the definition during its
     * most recent registration; absent when the flag is off. Consumed by
     * {@see self::persistRunStarted()} to pin new `flow_runs` rows to the
     * definition version/checksum that was active at registration time.
     * Internal bookkeeping only — never exposed through {@see self::definitions()}.
     *
     * @var array<string, array{version: int, checksum: string}>
     */
    private array $definitionVersionPins = [];

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
         *     definitions?: array{
         *         persist_registered?: bool
         *     },
         *     queue?: array{
         *         lock_store?: string|null,
         *         lock_seconds?: int,
         *         lock_retry_seconds?: int,
         *         tries?: mixed,
         *         backoff_seconds?: mixed
         *     },
         *     executor?: array{
         *         queue?: string|null,
         *         lock_store?: string|null,
         *         lock_seconds?: int|null,
         *         lock_retry_seconds?: int|null
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

        $this->persistRegisteredDefinitionIfEnabled($definition);
    }

    /**
     * Optional bridge to the v2 versioned definition store: compiles the
     * just-registered v1 definition to a graph and saves it as a new
     * `flow_definitions` draft, gated by `laravel-flow.definitions.persist_registered`
     * (default off). `DefinitionRepository` is resolved from the
     * container only when the flag is true, so the normal in-memory-only
     * registration path never touches the database or the container for
     * this feature. Re-registering an unchanged definition is a no-op:
     * {@see DefinitionRepository::createDraftIfChanged()} compares the
     * content checksum against the latest stored version (any status)
     * inside the same name-group lock as the insert, so repeated boots of
     * a host app that calls `register()` on every request/command — even
     * from concurrent workers — do not pile up draft versions for a
     * definition that never changed. Whichever version was matched or
     * produced is recorded in {@see self::$definitionVersionPins} so the
     * next run created for this definition name pins its `flow_runs` row
     * to that version/checksum (see {@see self::persistRunStarted()}).
     */
    private function persistRegisteredDefinitionIfEnabled(FlowDefinition $definition): void
    {
        if (! (bool) ($this->config['definitions']['persist_registered'] ?? false)) {
            // A long-lived FlowEngine instance (e.g. Octane) may already
            // hold a pin for this name from a moment when the flag was on;
            // turning the flag off must always mean unpinned going forward.
            unset($this->definitionVersionPins[$definition->name]);

            return;
        }

        /** @var DefinitionRepository $repository */
        $repository = $this->container->make(DefinitionRepository::class);

        try {
            // toGraphDefinition() throws InvalidGraphException when the
            // compiled graph is structurally invalid (e.g. zero steps on a
            // definition built directly, bypassing the builder's guard);
            // handled here alongside createDraftIfChanged()'s/checksum()'s
            // JsonException so registerDefinition() never leaks a raw
            // Graph-namespace exception for this opt-in feature.
            $graph = $definition->toGraphDefinition();

            // createDraftIfChanged() returns null when the graph is
            // unchanged from the latest stored version (dedupe skip),
            // inside the same name-group lock as its comparison. The
            // version pin still needs the MATCHED version in that case,
            // but latest() below is a second, UNLOCKED query issued after
            // that lock is released: if another process drafts a new
            // version for this name in between, latest() can return a row
            // that does not match the graph just registered. Verifying the
            // checksum here before trusting it as a pin turns that window
            // into "leave unpinned" instead of silently mispinning the run
            // to the wrong version/checksum.
            $stored = $repository->createDraftIfChanged($definition->name, $graph);

            if ($stored === null) {
                $checksum = (new GraphSerializer)->checksum($graph);
                $latest = $repository->latest($definition->name);

                $stored = $latest instanceof StoredDefinition && $latest->checksum === $checksum
                    ? $latest
                    : null;
            }
        } catch (QueryException $e) {
            throw $this->definitionPersistenceUnavailableException($e);
        } catch (JsonException|InvalidGraphException $e) {
            throw $this->definitionGraphInvalidException($definition->name, $e);
        } catch (DefinitionSignatureException $e) {
            throw $this->definitionSignatureUnverifiedException($definition->name, $e);
        }

        if ($stored instanceof StoredDefinition) {
            $this->definitionVersionPins[$definition->name] = [
                'version' => $stored->version,
                'checksum' => $stored->checksum,
            ];

            return;
        }

        // Losing the checksum race must leave THIS registration unpinned
        // even on a long-lived process (e.g. Octane) that already holds a
        // pin for $name from an earlier, unrelated registration — otherwise
        // the next run would silently reuse a stale version number instead
        // of going unpinned.
        unset($this->definitionVersionPins[$definition->name]);
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
     * Execute a graph definition synchronously through the v2 graph executor.
     *
     * @param  array<string, mixed>  $input
     */
    public function runGraph(GraphDefinition $graph, array $input, ?FlowExecutionOptions $options = null, string $definitionName = 'graph'): GraphRunResult
    {
        return $this->graphRunner()->run($graph, $input, $options, false, $definitionName);
    }

    /**
     * Dry-run a graph definition through the v2 graph executor (writes no rows).
     *
     * @param  array<string, mixed>  $input
     */
    public function dryRunGraph(GraphDefinition $graph, array $input, ?FlowExecutionOptions $options = null, string $definitionName = 'graph'): GraphRunResult
    {
        return $this->graphRunner()->run($graph, $input, $options, true, $definitionName);
    }

    private function graphRunner(): GraphRunner
    {
        /** @var GraphRunner $runner */
        $runner = $this->container->make(GraphRunner::class);

        return $runner;
    }

    /**
     * Dispatch a graph definition for queued execution: creates the run, seeds a
     * pending row per node, and dispatches the coordinator that fans nodes out
     * to per-node jobs. Returns the new run id. Requires persistence to be
     * enabled (the queued path coordinates through the run/node rows).
     *
     * @param  array<string, mixed>  $input
     */
    public function dispatchGraph(GraphDefinition $graph, array $input, ?FlowExecutionOptions $options = null, string $definitionName = 'graph'): string
    {
        if ($this->storeForExecution(false) === null) {
            throw new FlowExecutionException('Queued graph execution requires persistence to be enabled.');
        }

        /** @var QueueGraphCoordinator $coordinator */
        $coordinator = $this->container->make(QueueGraphCoordinator::class);
        $runId = $coordinator->start($graph, $input, $options, $definitionName);

        /** @var BusDispatcher $bus */
        $bus = $this->container->make(BusDispatcher::class);
        $bus->dispatch(new CoordinatorJob(
            runId: $runId,
            graph: $graph,
            definitionName: $definitionName,
            input: $input,
            queue: $this->executorQueue(),
            lockStore: $this->executorLockStore(),
            lockSeconds: $this->executorLockSeconds(),
            lockRetrySeconds: $this->executorLockRetrySeconds(),
        ));

        return $runId;
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
     * Requeue ONE failed webhook-outbox row (by its integer id) for delivery:
     * it is reset to `pending` with `attempts` cleared so the next
     * `flow:deliver-webhooks` pass re-attempts it. Intended for a dashboard
     * "redeliver" action driven by the dashboard read model's
     * `failedWebhookOutbox()` listing, which surfaces the integer id.
     *
     * Returns true only when a row with that id existed AND was in the `failed`
     * state (so an unknown id, an in-flight `delivering` lease, an already
     * `delivered` row, or an already `pending` row all return false — nothing
     * to redeliver, no state disturbed).
     */
    public function redeliverWebhook(int $outboxId): bool
    {
        // Guard persistence like every other DB-backed public method
        // (dispatchGraph(), the approval resume/reject path): a fresh app with
        // persistence disabled (the default) has no outbox table, so touching
        // the repository would surface a raw QueryException/500 instead of this
        // stable, typed failure. NOTE: intentionally NOT gated on
        // webhook.enabled — that flag governs whether NEW outbox rows are
        // recorded on flow events, not whether an already-failed row may be
        // redelivered.
        if ($this->storeForExecution(false) === null) {
            throw new FlowExecutionException('Webhook redelivery requires persistence to be enabled.');
        }

        /** @var EloquentWebhookOutboxRepository $repository */
        $repository = $this->container->make(EloquentWebhookOutboxRepository::class);

        try {
            return $repository->redeliver($outboxId);
        } catch (QueryException $e) {
            // Never leak the low-level SQL/driver error to an @api caller.
            throw new FlowExecutionException('Webhook redelivery failed.', previous: $e);
        }
    }

    /**
     * Cancel a non-terminal run: atomically transition the run to `aborted`
     * and move every still-active PERSISTED node to a terminal state (a
     * `pending` node becomes `skipped`, a `running`/`paused` node becomes
     * `failed` — the `NodeState` enum has no dedicated "cancelled" case, and
     * these are the transition-legal terminal targets). Idempotent: cancelling
     * an already-terminal run (or losing the compare-and-set race to a
     * concurrent completion) returns the run's CURRENT state unchanged rather
     * than forcing it. Requires persistence enabled — an in-memory run has no
     * cancellable persisted state. If the run's status keeps flapping between
     * non-terminal states (running<->paused) across a bounded retry budget so
     * the abort CAS never lands, it throws a {@see FlowExecutionException}
     * rather than returning a non-terminal run that only looks cancelled.
     *
     * SYNC vs QUEUED node coverage: only nodes that ALREADY have a
     * `flow_run_nodes` row are terminated. A QUEUED graph run pre-seeds a
     * `pending` row for every node up front, so all not-yet-run nodes flip to
     * `skipped`. A SYNCHRONOUSLY-executed run only writes a node row once that
     * node is reached, so a node downstream of the pause point has NO row and
     * is left un-rowed after cancel (it was never going to run once the run is
     * aborted). The run itself is terminal either way.
     *
     * Best-effort against a queued run: a node job already in flight may still
     * write its own row after this returns, but the run itself stays terminal
     * (the CAS pins `flow_runs.status` to `aborted`). NOTE: cancel does NOT
     * recompute the `flow_runs` node-count columns nor emit a broadcast
     * settle-point snapshot — a subscribed dashboard learns of the abort on
     * its next poll, and the counters reflect pre-cancel progress.
     *
     * @param  array<string, mixed>  $actor  reserved for parity with resume()/reject()
     *                                       and future audit attribution; the current
     *                                       implementation records no actor-scoped row
     */
    public function cancel(string $runId, array $actor = []): FlowRun
    {
        $store = $this->storeForExecution(false);

        if ($store === null) {
            throw new FlowExecutionException('Cancelling a run requires persistence to be enabled.');
        }

        // Retry against a BENIGN non-terminal transition (e.g. running -> paused
        // at an approval gate) that lands between our read and the CAS. Cancel
        // must only "lose" to a concurrent transition to a TERMINAL state
        // (idempotent no-op), never silently fail because the run merely flipped
        // running<->paused. Bounded so a pathological flap can't spin forever.
        for ($attempt = 0; $attempt < self::CANCEL_MAX_ATTEMPTS; $attempt++) {
            $record = $this->findRunRecordOrFail($store, $runId);

            $currentState = RunState::tryFrom($record->status);

            if ($currentState !== null && $currentState->isTerminal()) {
                return $this->flowRunFromRecord($record, $store); // already terminal — idempotent
            }

            $now = $this->now();
            $claimedRunRecord = null;

            try {
                $this->persistAtomically($store, function () use ($store, $runId, $record, $now, &$claimedRunRecord): void {
                    // CAS on the run's own current status.
                    $claimedRunRecord = $this->conditionalRuns($store)->updateWhereStatus($runId, $record->status, [
                        'status' => RunState::Aborted->value,
                        'finished_at' => $now,
                        'duration_ms' => $this->durationMs($record->started_at ?? $now, $now),
                    ]);

                    if (! ($claimedRunRecord instanceof FlowRunRecord)) {
                        return; // status changed under us — the outer loop re-reads and retries
                    }

                    foreach ($this->stepRecordsForRun($store, $runId) as $node) {
                        $nodeState = NodeState::tryFrom($node->status);

                        if ($nodeState === null || $nodeState->isTerminal()) {
                            continue;
                        }

                        // CAS on the just-read status (not an unconditional upsert):
                        // if a concurrent queued node job moved this node on (e.g.
                        // running -> succeeded) between the read above and here, the
                        // update matches zero rows and we leave the real outcome
                        // intact rather than clobbering it back to failed/skipped. A
                        // running/paused node has no dedicated "cancelled" state, so
                        // it lands on `failed` — stamp a distinguishing reason so it
                        // reads back as an explained cancellation, not an anonymous
                        // handler failure. Record duration for a node that was
                        // actually running; a never-started node has no started_at.
                        $terminalStatus = $nodeState === NodeState::Pending ? NodeState::Skipped->value : NodeState::Failed->value;
                        $isFailure = $terminalStatus === NodeState::Failed->value;

                        $store->runNodes()->terminate(
                            $runId,
                            $node->node_id,
                            $node->status,
                            $terminalStatus,
                            $now,
                            $node->started_at !== null ? $this->durationMs($node->started_at, $now) : null,
                            $isFailure ? 'FlowRunCancelled' : null,
                            $isFailure ? 'Run was cancelled.' : null,
                        );
                    }
                });
            } catch (QueryException $e) {
                // The transactional CAS/terminate can throw a raw driver error;
                // wrap it like every other persistence path so an @api caller
                // never sees SQL details.
                throw $this->flowPersistenceUnavailableException($e);
            }

            if ($claimedRunRecord instanceof FlowRunRecord) {
                return $this->flowRunFromRecord($claimedRunRecord, $store);
            }

            // CAS missed — the run's status changed. Loop to re-read the new
            // status and retry the abort against it.
        }

        // Exhausted retries. If the run reached a terminal state in the
        // meantime (a concurrent completion won), that's the idempotent result;
        // otherwise the status kept flapping under us and we could not cancel —
        // fail loudly rather than return a non-terminal run that looks cancelled.
        $final = $this->findRunRecordOrFail($store, $runId);
        $finalState = RunState::tryFrom($final->status);

        if ($finalState !== null && $finalState->isTerminal()) {
            return $this->flowRunFromRecord($final, $store);
        }

        throw new FlowExecutionException(sprintf(
            'Could not cancel flow run [%s]: its status changed concurrently across %d attempts.',
            $runId,
            self::CANCEL_MAX_ATTEMPTS,
        ));
    }

    /**
     * Load a run record by id (used by {@see self::cancel()} and
     * {@see self::replay()}), translating a persistence failure and a missing
     * run into typed engine exceptions. Pure lookup — no mutation.
     */
    private function findRunRecordOrFail(FlowStore $store, string $runId): FlowRunRecord
    {
        try {
            $record = $store->runs()->find($runId);
        } catch (QueryException $e) {
            throw $this->flowPersistenceUnavailableException($e);
        }

        if (! ($record instanceof FlowRunRecord)) {
            throw new FlowExecutionException(sprintf('Flow run [%s] was not found.', $runId));
        }

        return $record;
    }

    /**
     * Replay a TERMINAL persisted run as a NEW linked run: re-executes the
     * run's definition with its recorded input and returns the new {@see FlowRun}
     * (linked to the source via `replayedFromRunId`). A pinned graph run
     * re-executes its EXACT stored graph version (`DefinitionRepository::find`),
     * regardless of the current `latest()`; a legacy run re-executes the
     * currently-registered definition. Requires persistence enabled.
     *
     * `$options`: when null, links to the source run (its `correlationId` +
     * `replayedFromRunId`). When supplied, `replayedFromRunId` is forced to the
     * source run id (the linkage is the point of replay) while the caller's
     * `correlationId`/`idempotencyKey` are honored. CAVEAT: if the caller passes
     * an `idempotencyKey` already tied to an existing run, the legacy path
     * inherits `execute()`'s idempotency short-circuit and returns that EXISTING
     * run unchanged — so its `replayedFromRunId` reflects how it was originally
     * created, not necessarily this source run. Omit the key to always replay.
     *
     * Unlike the `flow:replay` console command this does NOT emit definition
     * drift warnings (there is no console) — the replay still uses the current
     * (or pinned) definition exactly as the command does.
     *
     * @throws FlowExecutionException for a missing / non-terminal / non-array-input
     *                                run, an unregistered definition, or an
     *                                unloadable stored graph version
     */
    public function replay(string $runId, ?FlowExecutionOptions $options = null): FlowRun
    {
        $store = $this->storeForExecution(false);

        if ($store === null) {
            throw new FlowExecutionException('Replaying a run requires persistence to be enabled.');
        }

        $original = $this->findRunRecordOrFail($store, $runId);

        $state = RunState::tryFrom($original->status);

        if ($state === null || ! $state->isTerminal()) {
            throw new FlowExecutionException(sprintf('Flow run [%s] is not terminal and cannot be replayed.', $runId));
        }

        $input = $original->input ?? [];

        if (! is_array($input)) {
            throw new FlowExecutionException(sprintf('Flow run [%s] does not have replayable array input.', $runId));
        }

        $replayOptions = $this->replayOptions($original, $options);

        if ($this->isPinnedGraphRun($original)) {
            return $this->replayPinnedGraph($store, $original, $input, $replayOptions);
        }

        try {
            $definition = $this->definition($original->definition_name);
        } catch (FlowNotRegisteredException $e) {
            throw new FlowExecutionException(sprintf('Flow definition [%s] is not registered.', $original->definition_name), previous: $e);
        }

        try {
            // execute() already wraps its own persistence QueryExceptions into
            // typed exceptions, so only its FlowExecutionException (rethrown as
            // is) and any other unexpected Throwable need handling here.
            return $this->execute($definition->name, $input, $replayOptions);
        } catch (FlowExecutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FlowExecutionException('Flow replay failed before a linked run could be completed.', previous: $e);
        }
    }

    private function isPinnedGraphRun(FlowRunRecord $run): bool
    {
        return $run->engine === 'graph'
            && $run->definition_version !== null
            && $run->definition_checksum !== null;
    }

    private function replayOptions(FlowRunRecord $original, ?FlowExecutionOptions $options): FlowExecutionOptions
    {
        if ($options === null) {
            return FlowExecutionOptions::make(
                correlationId: $original->correlation_id,
                replayedFromRunId: $original->id,
            );
        }

        // Honor the caller's correlation/idempotency choices but force the
        // replay linkage — the whole point of replay() is the source-run link.
        return FlowExecutionOptions::make(
            correlationId: $options->correlationId ?? $original->correlation_id,
            idempotencyKey: $options->idempotencyKey,
            replayedFromRunId: $original->id,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function replayPinnedGraph(FlowStore $store, FlowRunRecord $original, array $input, FlowExecutionOptions $options): FlowRun
    {
        $name = (string) $original->definition_name;
        $version = (int) $original->definition_version;

        try {
            /** @var DefinitionRepository $definitions */
            $definitions = $this->container->make(DefinitionRepository::class);
            $stored = $definitions->find($name, $version);
        } catch (Throwable $e) {
            // Covers a missing pinned version (DefinitionNotFoundException) and
            // an unreachable persistence connection alike.
            throw new FlowExecutionException(sprintf('Stored graph version [%d] for definition [%s] could not be loaded for replay.', $version, $name), previous: $e);
        }

        try {
            $graph = (new GraphSerializer)->fromArray($stored->graph);
        } catch (InvalidGraphException|JsonException $e) {
            throw new FlowExecutionException(sprintf('Stored graph version [%d] for definition [%s] could not be rebuilt for replay.', $version, $name), previous: $e);
        }

        try {
            // runGraph() already wraps its own persistence QueryExceptions.
            $result = $this->runGraph($graph, $input, $options, $name);
        } catch (FlowExecutionException $e) {
            throw $e; // preserve the specific message, matching the legacy path
        } catch (Throwable $e) {
            throw new FlowExecutionException('Flow graph replay failed before a linked run could be completed.', previous: $e);
        }

        // runGraph() returns a GraphRunResult; re-read the just-created run row
        // (it persisted synchronously before returning) to satisfy the : FlowRun
        // contract shared with the legacy path.
        try {
            $record = $store->runs()->find($result->runId);
        } catch (QueryException $e) {
            throw $this->flowPersistenceUnavailableException($e);
        }

        if (! ($record instanceof FlowRunRecord)) {
            throw new FlowExecutionException(sprintf('Replayed graph run [%s] could not be reloaded.', $result->runId));
        }

        $run = $this->flowRunFromRecord($record, $store);

        // A replayed graph that lands back on an approval gate issues a fresh
        // one-time token; it lives ONLY in the GraphRunResult (storage keeps the
        // hash only), so carry it onto the returned FlowRun — otherwise the
        // caller could never resume/reject the newly paused run (parity with the
        // legacy path, whose execute()-built FlowRun already holds its tokens).
        foreach ($result->approvalTokens as $token) {
            $run->recordApprovalToken($token);
        }

        return $run;
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
                        $this->recordWebhookOutbox(
                            event: 'flow.paused',
                            runId: $run->id,
                            approvalId: isset($result->output['approval_id']) && is_string($result->output['approval_id'])
                                ? $result->output['approval_id']
                                : null,
                            payload: [
                                'definition_name' => $definition->name,
                                'dry_run' => $dryRun,
                                'flow_run_id' => $run->id,
                                'occurred_at' => $stepFinishedAt->format(DateTimeInterface::ATOM),
                                'output' => $result->output,
                                'step_name' => $step->name,
                                'status' => 'paused',
                            ],
                            availableAt: $stepFinishedAt,
                            maxAttempts: $this->webhookMaxAttempts(),
                            redactor: $redactor,
                        );
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
            $finishedAt = $this->now();
            $run->markSucceeded($finishedAt);
            $this->persistAtomically($store, function () use ($store, $definition, $run, $context, $redactor, $finishedAt): void {
                $this->persistRunFinished($store, $run);
                $this->recordWebhookOutbox(
                    event: 'flow.completed',
                    runId: $run->id,
                    approvalId: null,
                    payload: $this->flowWebhookPayload(
                        definitionName: $definition->name,
                        runId: $run->id,
                        dryRun: $context->dryRun,
                        output: $this->runOutput($run),
                        status: FlowRun::STATUS_SUCCEEDED,
                        occurredAt: $finishedAt,
                    ),
                    availableAt: $finishedAt,
                    maxAttempts: $this->webhookMaxAttempts(),
                    redactor: $redactor,
                );
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
                $this->recordWebhookOutbox(
                    event: 'flow.failed',
                    runId: $run->id,
                    approvalId: null,
                    payload: $this->flowWebhookPayload(
                        definitionName: $definition->name,
                        runId: $run->id,
                        dryRun: $context->dryRun,
                        output: $this->runOutput($run),
                        status: FlowRun::STATUS_FAILED,
                        occurredAt: $stepFinishedAt,
                        stepName: $step->name,
                        errorClass: $error instanceof Throwable ? $error::class : null,
                        errorMessage: $this->safeErrorMessage($error, $redactor),
                    ),
                    availableAt: $stepFinishedAt,
                    maxAttempts: $this->webhookMaxAttempts(),
                    redactor: $redactor,
                );
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
        $preDecisionRunRecord = $this->approvalRunRecord($preDecisionApproval, $store);

        // Graph runs (engine === 'graph') never went through v1's step
        // compilation, so none of the v1-specific machinery below (step-order
        // drift checks, FlowContext/step-chain replay) applies to them —
        // dispatch to the engine-agnostic graph path instead. The lock (this
        // method's caller already holds it, run-id-keyed) and the token
        // consume step below are shared by both engines unchanged.
        if ($preDecisionRunRecord->engine === 'graph') {
            return $this->decideGraphApproval($decision, $payload, $actor, $store, $redactor, $token, $preDecisionApproval, $preDecisionRunRecord);
        }

        $preDecisionState = null;

        if ($preDecisionApproval->status === FlowApprovalRecord::STATUS_PENDING) {
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
     * Engine-agnostic approval decision for a graph run. Reuses the SAME
     * token-consume step as v1 ({@see consumeApprovalDecisionForPausedRun}) —
     * it only touches `flow_approvals`, never the run/node tables — then
     * delegates the actual resume/reject to {@see GraphApprovalCoordinator},
     * which mutates the paused node directly and re-drives the coordinator.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $actor
     */
    private function decideGraphApproval(
        string $decision,
        array $payload,
        array $actor,
        FlowStore $store,
        PayloadRedactor $redactor,
        string $token,
        FlowApprovalRecord $preDecisionApproval,
        FlowRunRecord $preDecisionRunRecord,
    ): FlowRun {
        // Mirrors the v1 pre-lock check: a PENDING token whose run already
        // left `paused` belongs to a gate this run moved past (or a run that
        // never reaches THIS token's gate under a different branch) — return
        // the current state without attempting to re-decide.
        if ($preDecisionApproval->status === FlowApprovalRecord::STATUS_PENDING
            && $preDecisionRunRecord->status !== FlowRun::STATUS_PAUSED
        ) {
            return $this->flowRunFromRecord($preDecisionRunRecord, $store);
        }

        [$approval, $consumedNow] = $this->consumeApprovalDecisionForPausedRun($token, $decision, $payload, $actor, $redactor);

        if (! $consumedNow) {
            $currentRun = $this->approvalRunRecord($approval, $store);

            // Crash-window recovery: the token is ALREADY decided (this branch
            // only reaches here once consumeApprovalDecisionForPausedRun found
            // it non-pending) but the run is STILL `paused` — a prior call
            // consumed the one-time token and then died (process crash,
            // transient queue/DB failure) before the coordinator ever mutated
            // the node or dispatched CoordinatorJob. The token can never be
            // resubmitted, so THIS call must re-drive the coordinator itself or
            // the run hangs paused forever. Safe to retry: the coordinator's own
            // node-row write and Paused->Running CAS are idempotent, and
            // CoordinatorJob's claim/finalize (C-PR8/9) tolerate duplicate
            // delivery by construction.
            if ($currentRun->status === FlowRun::STATUS_PAUSED) {
                return $this->flowRunFromRecord(
                    $this->driveGraphApprovalCoordinator($currentRun, $approval),
                    $store,
                );
            }

            // Otherwise a genuine duplicate resume/reject on an already fully
            // processed decision: idempotent, return current state without
            // re-advancing or re-compensating.
            return $this->flowRunFromRecord($currentRun, $store);
        }

        $updatedRun = $this->driveGraphApprovalCoordinator($preDecisionRunRecord, $approval);
        $flowRun = $this->flowRunFromRecord($updatedRun, $store);

        // Chained approval gates: if resuming/rejecting this gate advanced the
        // graph straight into ANOTHER flow.approval node, that downstream
        // node's token was already issued (NodeExecutor, mid-advance) but the
        // plain value has nowhere else to surface — mirrors v1's downstream-
        // gate token propagation on FlowRun::$approvalTokens.
        if ($updatedRun->status === FlowRun::STATUS_PAUSED) {
            $this->attachDownstreamGraphApprovalToken($flowRun, $updatedRun->id, $store, $redactor);
        }

        return $flowRun;
    }

    private function driveGraphApprovalCoordinator(FlowRunRecord $run, FlowApprovalRecord $approval): FlowRunRecord
    {
        $decisionPayload = $approval->payload ?? [];
        $coordinator = $this->graphApprovalCoordinator();

        return $approval->status === FlowApprovalRecord::STATUS_APPROVED
            ? $coordinator->resume($run, $approval->step_name, $decisionPayload)
            : $coordinator->reject($run, $approval->step_name, $decisionPayload);
    }

    private function attachDownstreamGraphApprovalToken(FlowRun $flowRun, string $runId, FlowStore $store, PayloadRedactor $redactor): void
    {
        // A graph can legitimately settle on MULTIPLE simultaneously-paused
        // approval gates — e.g. two independent branches both advancing into
        // their own gate in the same coordinator pass, or a branch that was
        // already paused before this resume alongside a newly-paused one.
        // Surface every one of them, not only the first found, or the others
        // become unrecoverable to callers who only have the public API.
        $pausedNodeIds = [];

        foreach ($this->stepRecordsForRun($store, $runId) as $nodeRecord) {
            if ($nodeRecord->status === NodeState::Paused->value) {
                $pausedNodeIds[] = (string) $nodeRecord->node_id;
            }
        }

        $manager = $this->approvalTokenManager($redactor);

        foreach ($pausedNodeIds as $pausedNodeId) {
            try {
                $reissued = $manager->reissuePendingForStep($runId, $pausedNodeId);
            } catch (QueryException $e) {
                throw $this->approvalPersistenceUnavailableException($e);
            }

            if ($reissued instanceof IssuedApprovalToken) {
                $flowRun->approvalTokens[$pausedNodeId] = $reissued;
            }
        }
    }

    private function graphApprovalCoordinator(): GraphApprovalCoordinator
    {
        /** @var GraphApprovalCoordinator $coordinator */
        $coordinator = $this->container->make(GraphApprovalCoordinator::class);

        return $coordinator;
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

        if (! ($cacheStore instanceof LockProvider)) {
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

        if ($this->persistedDownstreamPausedApprovalGate($approval, $store, $runRecord) instanceof FlowRunNodeRecord) {
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
            if ($stepRecord->node_id !== $approval->step_name) {
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
            if ($stepRecord->node_id === $approval->step_name) {
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
    ): ?FlowRunNodeRecord {
        if ($runRecord->status !== FlowRun::STATUS_PAUSED
            || $approval->status !== FlowApprovalRecord::STATUS_APPROVED
        ) {
            return null;
        }

        $approvalSequence = null;

        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            if ($stepRecord->node_id === $approval->step_name) {
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
        FlowRunNodeRecord $approvalStepRecord,
    ): FlowRun {
        $run = $this->flowRunFromRecord($runRecord, $store);

        try {
            $token = $this->approvalTokenManager()->reissuePendingForStep($runRecord->id, $approvalStepRecord->node_id);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }

        if ($token instanceof IssuedApprovalToken) {
            $output = is_array($approvalStepRecord->outputs) ? $approvalStepRecord->outputs : [];
            $output['approval_expires_at'] = $token->expiresAt->format(DateTimeInterface::ATOM);
            try {
                $refreshedStep = $store->runNodes()->createOrUpdate($runRecord->id, $approvalStepRecord->node_id, [
                    'business_impact' => $approvalStepRecord->business_impact,
                    'dry_run_skipped' => (bool) $approvalStepRecord->dry_run_skipped,
                    'duration_ms' => $approvalStepRecord->duration_ms,
                    'error_class' => $approvalStepRecord->error_class,
                    'error_message' => $approvalStepRecord->error_message,
                    'finished_at' => $approvalStepRecord->finished_at,
                    'handler' => $approvalStepRecord->handler,
                    'inputs' => $approvalStepRecord->inputs,
                    'node_type' => $approvalStepRecord->node_type,
                    'outputs' => $output,
                    'sequence' => $approvalStepRecord->sequence,
                    'started_at' => $approvalStepRecord->started_at,
                    'status' => $approvalStepRecord->status,
                ]);
            } catch (QueryException $e) {
                throw $this->flowPersistenceUnavailableException($e);
            }
            $result = $this->flowStepResultFromRecord($refreshedStep);

            if ($result instanceof FlowStepResult) {
                $run->recordStepResult($refreshedStep->node_id, $result);
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
            if ($stepRecord->node_id === $approval->step_name) {
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

    private function hasPersistedDownstreamCompletedStep(
        FlowApprovalRecord $approval,
        FlowStore $store,
        FlowRunRecord $runRecord,
    ): bool {
        if ($approval->status !== FlowApprovalRecord::STATUS_APPROVED) {
            return false;
        }

        $approvalSequence = null;

        foreach ($this->stepRecordsForRun($store, $runRecord->id) as $stepRecord) {
            if ($stepRecord->node_id === $approval->step_name) {
                if ($stepRecord->status !== 'succeeded') {
                    return false;
                }

                $approvalSequence = $stepRecord->sequence;

                continue;
            }

            if ($approvalSequence !== null
                && $stepRecord->sequence > $approvalSequence
                && in_array($stepRecord->status, ['succeeded', 'skipped'], true)
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

        if (! ($store instanceof FlowStore)) {
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

        if (! ($approval instanceof FlowApprovalRecord) || $approval->status === FlowApprovalRecord::STATUS_EXPIRED) {
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

            if ($downstreamPausedApprovalGate instanceof FlowRunNodeRecord) {
                return $this->flowRunFromRecordWithReissuedApprovalToken($runRecord, $store, $downstreamPausedApprovalGate);
            }
        }

        if (! $consumedNow && $this->hasPersistedDownstreamApprovalGate($approval, $store, $runRecord)) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        if (! $consumedNow && $this->hasPersistedDownstreamPause($approval, $store, $runRecord)) {
            return $this->flowRunFromRecord($runRecord, $store);
        }

        if ($runRecord->status === FlowRun::STATUS_RUNNING
            && ! $this->hasPersistedDownstreamCompletedStep($approval, $store, $runRecord)
        ) {
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

            if (! ($claimedRunRecord instanceof FlowRunRecord)) {
                $currentRunRecord = $this->approvalCurrentRunRecord($store, $run->id);

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

                if (! ($claimedRunRecord instanceof FlowRunRecord)) {
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
                $this->recordWebhookOutbox(
                    event: 'flow.resumed',
                    runId: $run->id,
                    approvalId: $approval->id,
                    payload: $this->flowWebhookPayload(
                        definitionName: $state->definition->name,
                        runId: $run->id,
                        dryRun: $state->context->dryRun,
                        output: $this->runOutput($run),
                        status: FlowRun::STATUS_RUNNING,
                        occurredAt: $finishedAt,
                        stepName: $approval->step_name,
                    ),
                    availableAt: $finishedAt,
                    maxAttempts: $this->webhookMaxAttempts(),
                    redactor: $redactor,
                );
                $this->persistRunFinished($store, $run);
            });

            if (! ($claimedRunRecord instanceof FlowRunRecord)) {
                $currentRunRecord = $this->approvalCurrentRunRecord($store, $run->id);

                if ($currentRunRecord instanceof FlowRunRecord) {
                    return $this->flowRunFromRecord($currentRunRecord, $store);
                }

                throw new FlowExecutionException(sprintf('Flow run [%s] was not found while claiming approval resume.', $run->id));
            }

            $this->dispatchOrCaptureListenerFailure(
                new FlowStepCompleted($run->id, $state->definition->name, $approval->step_name, $result, false),
                $listenerFailureEvent,
            );
        } catch (ApprovalPersistenceException $e) {
            throw $e;
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
                $approval,
                &$claimedRunRecord,
            ): void {
                $claimedRunRecord = $this->conditionalRuns($store)->updateWhereStatus($run->id, FlowRun::STATUS_PAUSED, [
                    'duration_ms' => null,
                    'finished_at' => null,
                    'status' => FlowRun::STATUS_RUNNING,
                ]);

                if (! ($claimedRunRecord instanceof FlowRunRecord)) {
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
                $this->recordWebhookOutbox(
                    event: 'flow.failed',
                    runId: $run->id,
                    approvalId: $approval->id,
                    payload: $this->flowWebhookPayload(
                        definitionName: $state->definition->name,
                        runId: $run->id,
                        dryRun: $state->context->dryRun,
                        output: $this->runOutput($run),
                        status: FlowRun::STATUS_FAILED,
                        occurredAt: $finishedAt,
                        stepName: $state->approvalStep->name,
                        errorClass: $error instanceof Throwable ? $error::class : null,
                        errorMessage: $this->safeErrorMessage($error, $redactor),
                    ),
                    availableAt: $finishedAt,
                    maxAttempts: $this->webhookMaxAttempts(),
                    redactor: $redactor,
                );
                $this->persistRunFinished($store, $run);
            });

            if (! ($claimedRunRecord instanceof FlowRunRecord)) {
                $currentRunRecord = $this->approvalCurrentRunRecord($store, $run->id);

                if ($currentRunRecord instanceof FlowRunRecord) {
                    return $this->flowRunFromRecord($currentRunRecord, $store);
                }

                throw new FlowExecutionException(sprintf('Flow run [%s] was not found while claiming approval reject.', $run->id));
            }

            $this->dispatchOrCaptureListenerFailure(
                new FlowStepFailed($run->id, $state->definition->name, $state->approvalStep->name, $result, false),
                $listenerFailureEvent,
            );
        } catch (ApprovalPersistenceException $e) {
            throw $e;
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

        if (! ($runs instanceof ConditionalRunRepository)) {
            throw new FlowExecutionException(sprintf(
                'This operation requires the run repository to implement %s.',
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
                $run->recordStepResult($stepRecord->node_id, $result);
            }

            $stepIndex = $this->stepIndex($definition, $stepRecord->node_id);

            if ($stepIndex === null || $stepIndex !== $expectedIndex) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because persisted step [%s] does not match the current flow definition.',
                    $stepRecord->node_id,
                ));
            }

            if ($stepRecord->handler !== $definition->steps[$stepIndex]->handlerFqcn) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because persisted step [%s] does not match the current flow definition.',
                    $stepRecord->node_id,
                ));
            }

            if ($stepIndex < $approvalIndex && ! in_array($stepRecord->status, ['succeeded', 'skipped'], true)) {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because prior step [%s] is [%s].',
                    $stepRecord->node_id,
                    $stepRecord->status,
                ));
            }

            if ($stepIndex < $approvalIndex
                && $stepRecord->status === 'succeeded'
                && ! $stepRecord->dry_run_skipped
            ) {
                $context = $context->withStepOutput($stepRecord->node_id, $stepRecord->outputs ?? []);
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
                    $retryContext = $context->withStepOutput($stepRecord->node_id, $stepRecord->outputs ?? []);
                    $retryCompletedSteps = [...$completedSteps, $approvalStep];
                    $retrySequence = $stepRecord->sequence;
                    $retryStartIndex = $approvalIndex + 1;
                } elseif (! in_array($stepRecord->status, ['paused', 'failed'], true)) {
                    throw new FlowExecutionException(sprintf(
                        'Cannot resume approval because approval step [%s] is [%s].',
                        $stepRecord->node_id,
                        $stepRecord->status,
                    ));
                }

                $expectedIndex++;

                continue;
            }

            if (! ($approvalStepRecord instanceof FlowRunNodeRecord) || $approvalStepRecord->status !== 'succeeded') {
                throw new FlowExecutionException(sprintf(
                    'Cannot resume approval because downstream step [%s] was persisted before the approval gate succeeded.',
                    $stepRecord->node_id,
                ));
            }

            if ($stepRecord->status === 'paused' && $runRecord->status === FlowRun::STATUS_PAUSED) {
                $pausedDownstreamStep = $stepRecord->node_id;
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
                    $stepRecord->node_id,
                    $stepRecord->status,
                ));
            }

            if ($stepRecord->status === 'succeeded' && ! $stepRecord->dry_run_skipped) {
                $retryContext = $retryContext->withStepOutput($stepRecord->node_id, $stepRecord->outputs ?? []);
            }

            $retryCompletedSteps[] = $definition->steps[$stepIndex];
            $retrySequence = $stepRecord->sequence;
            $retryStartIndex = $stepIndex + 1;
            $expectedIndex++;
        }

        if (! ($approvalStepRecord instanceof FlowRunNodeRecord)) {
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

        if (! ($runRecord instanceof FlowRunRecord)) {
            throw new FlowExecutionException(sprintf('Approval run [%s] was not found.', $approval->run_id));
        }

        return $runRecord;
    }

    private function approvalCurrentRunRecord(FlowStore $store, string $runId): ?FlowRunRecord
    {
        try {
            return $store->runs()->find($runId);
        } catch (QueryException $e) {
            throw $this->approvalPersistenceUnavailableException($e);
        }
    }

    private function approvalPersistenceUnavailableException(QueryException $e): ApprovalPersistenceException
    {
        return new ApprovalPersistenceException(
            'Approval resume/reject requires published laravel-flow persistence tables and a reachable persistence connection. Run the package migrations and verify the persistence connection.',
            previous: $e,
        );
    }

    /**
     * @return iterable<FlowRunNodeRecord>
     */
    private function stepRecordsForRun(FlowStore $store, string $runId): iterable
    {
        try {
            return $store->runNodes()->forRun($runId);
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

    private function definitionPersistenceUnavailableException(QueryException $e): FlowExecutionException
    {
        return new FlowExecutionException(
            'Persisting registered definitions (laravel-flow.definitions.persist_registered) requires the published flow_definitions persistence table and a reachable persistence connection. Run the package migrations and verify the persistence connection, or disable definitions.persist_registered.',
            previous: $e,
        );
    }

    private function definitionGraphInvalidException(string $definitionName, JsonException|InvalidGraphException $e): FlowExecutionException
    {
        $reason = $e instanceof InvalidGraphException
            ? 'its compiled graph is structurally invalid'
            : 'its content checksum could not be computed (non-UTF-8 or otherwise non-JSON-encodable step handler names or config)';

        return new FlowExecutionException(sprintf(
            'Definition [%s] could not be persisted as a registered definition (laravel-flow.definitions.persist_registered): %s.',
            $definitionName,
            $reason,
        ), previous: $e);
    }

    private function definitionSignatureUnverifiedException(string $definitionName, DefinitionSignatureException $e): FlowExecutionException
    {
        return new FlowExecutionException(sprintf(
            'Definition [%s] could not be persisted as a registered definition (laravel-flow.definitions.persist_registered): the latest stored `flow_definitions` version failed signature verification (laravel-flow.definitions.signing_secret is enabled). The stored graph may have been edited outside the repository.',
            $definitionName,
        ), previous: $e);
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

        if (! ($handler instanceof FlowStepHandler)) {
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

            if (! ($compensator instanceof FlowCompensator)) {
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

        if (! ($compensator instanceof FlowCompensator)) {
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordWebhookOutbox(
        string $event,
        ?string $runId,
        ?string $approvalId,
        array $payload,
        ?DateTimeInterface $availableAt = null,
        ?int $maxAttempts = null,
        ?PayloadRedactor $redactor = null,
    ): void {
        /** @var EloquentWebhookOutboxRepository $repository */
        $repository = $this->container->make(EloquentWebhookOutboxRepository::class);

        $repository->createPending(
            event: $event,
            runId: $runId,
            approvalId: $approvalId,
            payload: $payload,
            availableAt: $availableAt,
            maxAttempts: $maxAttempts ?? $this->webhookMaxAttempts(),
            redactor: $redactor,
        );
    }

    private function webhookMaxAttempts(): int
    {
        $webhook = $this->config['webhook'] ?? [];

        if (! is_array($webhook)) {
            return 3;
        }

        $maxAttempts = $webhook['max_attempts'] ?? 3;

        return is_int($maxAttempts) && $maxAttempts >= 1 ? $maxAttempts : 3;
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @return array<string, mixed>
     */
    private function flowWebhookPayload(
        string $definitionName,
        string $runId,
        bool $dryRun,
        ?array $output,
        string $status,
        DateTimeInterface $occurredAt,
        ?string $stepName = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): array {
        $payload = [
            'definition_name' => $definitionName,
            'dry_run' => $dryRun,
            'flow_run_id' => $runId,
            'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
            'output' => $output,
            'status' => $status,
        ];

        if ($stepName !== null) {
            $payload['step_name'] = $stepName;
        }

        if ($errorClass !== null) {
            $payload['error_class'] = $errorClass;
        }

        if ($errorMessage !== null) {
            $payload['error_message'] = $errorMessage;
        }

        return $payload;
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

        $pin = $this->definitionVersionPins[$run->definitionName] ?? null;

        if ($pin !== null) {
            $attributes['definition_version'] = $pin['version'];
            $attributes['definition_checksum'] = $pin['checksum'];
        }

        $store->runs()->create($attributes);
    }

    private function existingRunForIdempotency(
        ?FlowStore $store,
        FlowDefinition $definition,
        FlowExecutionOptions $options,
    ): ?FlowRun {
        if (! ($store instanceof FlowStore) || $options->idempotencyKey === null) {
            return null;
        }

        $existingRun = $store->runs()->findByIdempotencyKey($options->idempotencyKey);

        if (! ($existingRun instanceof FlowRunRecord)) {
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
                    $run->recordStepResult($stepRecord->node_id, $result);
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

    private function flowStepResultFromRecord(FlowRunNodeRecord $record): ?FlowStepResult
    {
        if ($record->status === 'failed') {
            return FlowStepResult::failed(new FlowExecutionException(
                $record->error_message ?? 'Persisted flow step failed.',
            ));
        }

        if ($record->status === 'paused') {
            return FlowStepResult::paused($record->outputs ?? [], $record->business_impact);
        }

        if ($record->status === 'skipped' || $record->dry_run_skipped) {
            return FlowStepResult::dryRunSkipped();
        }

        if ($record->status !== 'succeeded') {
            return null;
        }

        return FlowStepResult::success($record->outputs ?? [], $record->business_impact);
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

        $store->runNodes()->createOrUpdate($run->id, $step->name, [
            'dry_run_skipped' => false,
            'handler' => $step->handlerFqcn,
            'inputs' => $this->stepInputSnapshot($context),
            'node_type' => FlowDefinition::LEGACY_NODE_TYPE,
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

        $store->runNodes()->createOrUpdate($run->id, $step->name, [
            'business_impact' => $result->businessImpact,
            'duration_ms' => $this->durationMs($startedAt, $finishedAt),
            'dry_run_skipped' => $result->dryRunSkipped,
            'error_class' => $error instanceof Throwable ? $error::class : null,
            'error_message' => $this->safeErrorMessage($error, $redactor),
            'finished_at' => $finishedAt,
            'handler' => $step->handlerFqcn,
            'inputs' => $this->stepInputSnapshot($context),
            'node_type' => FlowDefinition::LEGACY_NODE_TYPE,
            'outputs' => $result->success ? $result->output : null,
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
        if (! ($error instanceof Throwable)) {
            return null;
        }

        return $this->errorMessageRedactor()->redact($error->getMessage(), $redactor ?? $this->redactorForExecution());
    }

    private function errorMessageRedactor(): ErrorMessageRedactor
    {
        $persistence = $this->config['persistence'] ?? [];
        $redaction = is_array($persistence) ? ($persistence['redaction'] ?? []) : [];

        return new ErrorMessageRedactor(is_array($redaction) ? $redaction : []);
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
        if (! ($token instanceof IssuedApprovalToken)) {
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
        if (! ($store instanceof RedactorAwareFlowStore) || ! ($redactor instanceof PayloadRedactor)) {
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

    private function executorQueue(): ?string
    {
        $queue = $this->config['executor']['queue'] ?? null;

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    private function executorLockStore(): ?string
    {
        $store = $this->config['executor']['lock_store'] ?? null;

        if (is_string($store) && $store !== '') {
            return $store;
        }

        // Fall back to the v1 queue lock store (which itself falls back to
        // cache.default) so a host that configured `queue.lock_store` gets the
        // same shared store for queued graphs without a separate executor knob.
        return $this->queueLockStore();
    }

    private function executorLockSeconds(): int
    {
        $seconds = $this->config['executor']['lock_seconds'] ?? null;

        if (is_int($seconds) && $seconds >= 1) {
            return $seconds;
        }

        return $this->queueLockSeconds();
    }

    private function executorLockRetrySeconds(): int
    {
        $seconds = $this->config['executor']['lock_retry_seconds'] ?? null;

        if (is_int($seconds) && $seconds >= 1) {
            return $seconds;
        }

        return $this->queueLockRetrySeconds();
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
