<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Broadcasting\GraphProgressBroadcaster;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\IssuedApprovalToken;

/**
 * Synchronous graph executor: the correctness reference for the queued
 * coordinator (C-PR5). It drives a {@see ReadinessResolver} loop, running each
 * ready node through the shared {@see NodeExecutor}, marking poisoned-upstream
 * nodes `blocked`, and rolling the per-node states up into a {@see RunState}.
 * A dry run passes a null store so it writes zero rows.
 *
 * @api
 */
final class GraphRunner
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly NodeExecutor $executor,
        private readonly ReadinessResolver $readiness,
        private readonly Closure $clock,
        private readonly ?FlowStore $store = null,
        private readonly ?GraphSaga $saga = null,
        private readonly string $compensationStrategy = GraphSaga::STRATEGY_REVERSE_ORDER,
        private readonly ?GraphProgressBroadcaster $progressBroadcaster = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(
        GraphDefinition $graph,
        array $input,
        ?FlowExecutionOptions $options = null,
        bool $dryRun = false,
        string $definitionName = 'graph',
    ): GraphRunResult {
        $options ??= new FlowExecutionOptions;
        $store = $dryRun ? null : $this->store;
        $runId = $this->generateId();
        $startedAt = ($this->clock)();

        $sequenceOf = array_flip($graph->topologicalOrder());
        $nodesTotal = count($graph->nodeIds());

        // Root nodes (no incoming wire) receive the run input on the conventional
        // `input` port — this is how the compiled v1 first step reads the flow
        // input, and it is a harmless no-op for graph nodes without an `input`
        // port (the router only reads config for ports the node actually has).
        $hasIncoming = NodeRouting::nodesWithIncoming($graph);

        $this->persistRunStarted($store, $graph, $runId, $definitionName, $input, $dryRun, $nodesTotal, $startedAt, $options);

        /** @var array<string, NodeState> $states */
        $states = [];
        /** @var array<string, array<string, mixed>> $outputs */
        $outputs = [];
        /** @var array<string, string> $errors */
        $errors = [];
        /** @var array<string, IssuedApprovalToken> $approvalTokens */
        $approvalTokens = [];

        while (true) {
            $decision = $this->readiness->resolve($graph, $states);

            if ($decision->allTerminal) {
                break;
            }

            $progressed = false;

            foreach ($decision->blocked as $id) {
                $states[$id] = NodeState::Blocked;
                $this->persistBlocked($store, $runId, $graph, $id, $sequenceOf[$id] ?? null, $dryRun);
                $progressed = true;
            }

            foreach ($decision->ready as $id) {
                $node = $graph->node($id);

                if ($node === null) {
                    continue;
                }

                $node = NodeRouting::seedRootInput($node, ! isset($hasIncoming[$id]), $input);

                $execution = $this->executor->execute(
                    $runId,
                    $definitionName,
                    $node,
                    NodeRouting::connectionsInto($graph, $id, $sequenceOf),
                    $outputs,
                    $dryRun,
                    $sequenceOf[$id] ?? 0,
                    $store,
                );

                $states[$id] = $execution->state;

                if ($execution->state === NodeState::Succeeded) {
                    $outputs[$id] = $execution->outputs;
                }

                if ($execution->error !== null) {
                    $errors[$id] = $execution->error->getMessage();
                }

                if ($execution->issuedApprovalToken !== null) {
                    $approvalTokens[$id] = $execution->issuedApprovalToken;
                }

                $progressed = true;
            }

            if (! $progressed) {
                break; // safety net: no ready node and nothing new blocked
            }
        }

        $runState = RunRollup::state($graph, $states);

        // Persist the terminal state BEFORE compensation — v1's order, and the
        // same order as the queued coordinator's finalizeRun: finished_at /
        // duration_ms measure EXECUTION only (not rollback time), and a fatal
        // inside a user compensator can no longer strand the run row `running`.
        $this->persistRunFinished($store, $runId, $runState, $states, $outputs, $startedAt);

        // Aggregate progress snapshot broadcasts AFTER the persist above — same
        // "durable before observable" ordering as the queued coordinator (which
        // re-reads its just-committed row rather than broadcast mid-transaction).
        // Still decoupled from persistence.enabled: a subscriber-visible
        // settle-point is announced regardless of whether $store is null, only
        // ! $dryRun gates it (a dry run has zero externally-observable side
        // effects).
        $counters = RunRollup::counters($states);

        if (! $dryRun && $this->progressBroadcaster !== null) {
            $this->progressBroadcaster->runProgressUpdated($runId, $runState, $nodesTotal, $counters['completed'], $counters['failed']);
        }

        // Graph saga: a failed run rolls back its COMPLETED nodes (reverse-
        // topological order / opt-in parallel; aggregate compensator last).
        // Never on a dry run — compensation is a real side effect. The run is
        // marked `compensated` ONLY when every intended compensator succeeded;
        // the outcome is persisted as a follow-up update to the already-
        // terminal row (Failed/PartiallySucceeded -> Compensated is a legal
        // transition).
        if (! $dryRun && $this->saga !== null && in_array($runState, [RunState::Failed, RunState::PartiallySucceeded], true)) {
            $sagaReport = $this->saga->compensate($runId, $definitionName, $graph, $states, $outputs, $this->compensationStrategy, $input);

            if ($sagaReport->attempted()) {
                if ($sagaReport->fullySucceeded()) {
                    $runState = RunState::Compensated;
                }

                $this->persistCompensationOutcome($store, $runId, $sagaReport);

                // A subscriber that already saw the pre-compensation snapshot
                // (failed/partially_succeeded, broadcast above) must also see
                // the run settle as `compensated` on a full rollback — a
                // second, ACCURATE snapshot after persisting the outcome,
                // never before it (same durable-before-observable ordering).
                if ($this->progressBroadcaster !== null && $runState === RunState::Compensated) {
                    $this->progressBroadcaster->runProgressUpdated($runId, $runState, $nodesTotal, $counters['completed'], $counters['failed']);
                }
            }
        }

        return new GraphRunResult($runId, $runState, $states, $outputs, $errors, $approvalTokens);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persistRunStarted(
        ?FlowStore $store,
        GraphDefinition $graph,
        string $runId,
        string $definitionName,
        array $input,
        bool $dryRun,
        int $nodesTotal,
        DateTimeImmutable $startedAt,
        FlowExecutionOptions $options,
    ): void {
        if ($store === null) {
            return;
        }

        $attributes = [
            'definition_name' => $definitionName,
            'correlation_id' => $options->correlationId,
            'dry_run' => $dryRun,
            'engine' => 'graph',
            'id' => $runId,
            'idempotency_key' => $options->idempotencyKey,
            'input' => $input,
            'nodes_total' => $nodesTotal,
            'started_at' => $startedAt,
            'status' => RunState::Running->value,
        ];

        // Store the canonical graph (unredacted structure) on EVERY graph run,
        // not only queued ones: a synchronously-started run can still pause on
        // an approval gate, and resuming it later needs to reload the graph by
        // run id — same rationale as QueueGraphCoordinator::start()'s write of
        // this column (and flow_definitions.graph before it).
        if (! $dryRun) {
            $attributes['graph'] = (new GraphSerializer)->toArray($graph);
        }

        if ($options->replayedFromRunId !== null) {
            $attributes['replayed_from_run_id'] = $options->replayedFromRunId;
        }

        $store->runs()->create($attributes);
    }

    private function persistBlocked(?FlowStore $store, string $runId, GraphDefinition $graph, string $nodeId, ?int $sequence, bool $dryRun): void
    {
        $node = $graph->node($nodeId);

        if ($node === null) {
            return;
        }

        // Persist FIRST, broadcast SECOND — same durable-before-observable
        // ordering as NodeExecutor::persist() and the queued coordinator: a
        // subscriber must never observe `blocked` before the durable
        // `flow_run_nodes` row exists.
        if ($store !== null) {
            $store->runNodes()->createOrUpdate($runId, $nodeId, [
                'node_type' => $node->type,
                'sequence' => $sequence,
                'status' => NodeState::Blocked->value,
                'dry_run_skipped' => false,
                'finished_at' => ($this->clock)(),
            ]);
        }

        // Blocked nodes never reach NodeExecutor::persist() (poison propagation
        // marks them directly, no handler attempt) — broadcast here too, or a
        // live monitor would see the aggregate snapshot count them as failed
        // with no per-node transition event to explain why. Still decoupled
        // from persistence.enabled (fires even when $store is null); gated on
        // ! $dryRun like every other broadcast — a dry run is a simulation
        // with zero externally-observable side effects.
        if (! $dryRun && $this->progressBroadcaster !== null) {
            $this->progressBroadcaster->nodeTransitioned($runId, $nodeId, $node->type, NodeState::Blocked, $sequence ?? 0);
        }
    }

    /**
     * Record the saga outcome on the already-terminal run row (v1 vocabulary):
     * `compensated` flips only on a FULL rollback (which also advances the
     * status to `compensated`); a partial one records
     * `compensation_status = 'failed'` while the run keeps its failure state.
     */
    private function persistCompensationOutcome(?FlowStore $store, string $runId, GraphSagaReport $sagaReport): void
    {
        if ($store === null) {
            return;
        }

        $attributes = [
            'compensated' => $sagaReport->fullySucceeded(),
            'compensation_status' => $sagaReport->fullySucceeded() ? 'succeeded' : 'failed',
        ];

        if ($sagaReport->fullySucceeded()) {
            $attributes['status'] = RunState::Compensated->value;
        }

        $store->runs()->update($runId, $attributes);
    }

    /**
     * @param  array<string, NodeState>  $states
     * @param  array<string, array<string, mixed>>  $outputs
     */
    private function persistRunFinished(
        ?FlowStore $store,
        string $runId,
        RunState $runState,
        array $states,
        array $outputs,
        DateTimeImmutable $startedAt,
    ): void {
        if ($store === null) {
            return;
        }

        $counters = RunRollup::counters($states);

        $attributes = [
            'status' => $runState->value,
            'output' => $outputs,
            'nodes_completed' => $counters['completed'],
            'nodes_failed' => $counters['failed'],
        ];

        // A paused run is not finished: keep finished_at / duration_ms null so
        // it is not treated as completed (matching v1's paused-run invariant).
        if ($runState !== RunState::Paused) {
            $finishedAt = ($this->clock)();
            $attributes['finished_at'] = $finishedAt;
            $attributes['duration_ms'] = (int) round(((float) $finishedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000);
        }

        $store->runs()->update($runId, $attributes);
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
