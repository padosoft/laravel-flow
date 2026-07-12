<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;

/**
 * Drives a queued graph run. {@see self::start()} creates the run row and
 * pre-seeds one `pending` node row per graph node (so the compare-and-set claim
 * has a row to flip). {@see self::advance()} runs one advancement pass — inside
 * a `flow_runs` row lock that serializes concurrent coordinators — resolving
 * readiness, marking newly-poisoned nodes `blocked`, and atomically claiming
 * each ready node (`pending` -> `running`). Only the claim winner's node id is
 * returned, so a caller dispatches a node job at most once even under duplicate
 * coordinator delivery. When every node is terminal the run is finalized (roll
 * up {@see RunState}); a blocked/failed run finalizes rather than hanging.
 *
 * Node execution itself and job dispatch live in the {@see CoordinatorJob}
 * / {@see Jobs\NodeJob} pair, which share the synchronous {@see GraphRunner}'s
 * {@see NodeExecutor} seam so the queued and synchronous paths cannot diverge.
 *
 * @internal
 */
final class QueueGraphCoordinator
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly FlowStore $store,
        private readonly ReadinessResolver $readiness,
        private readonly Closure $clock,
        private readonly ?string $connectionName = null,
        private readonly ?JoinCoordinator $join = null,
        private readonly ?BusDispatcher $bus = null,
        private readonly ?string $queue = null,
        private readonly ?string $lockStore = null,
        private readonly int $lockSeconds = 3600,
        private readonly int $lockRetrySeconds = 30,
        private readonly ?GraphSaga $saga = null,
        private readonly string $compensationStrategy = GraphSaga::STRATEGY_REVERSE_ORDER,
    ) {}

    /**
     * Create the run and pre-seed a `pending` row for every node. Returns the
     * new run id. Run row and node rows commit together so a coordinator that
     * starts advancing (after commit) always sees a complete pending graph.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(
        GraphDefinition $graph,
        array $input,
        ?FlowExecutionOptions $options,
        string $definitionName,
    ): string {
        $options ??= new FlowExecutionOptions;
        $runId = $this->generateId();
        $startedAt = ($this->clock)();
        $sequenceOf = array_flip($graph->topologicalOrder());

        $this->connection()->transaction(function () use ($graph, $runId, $definitionName, $input, $options, $startedAt, $sequenceOf): void {
            $attributes = [
                'id' => $runId,
                'definition_name' => $definitionName,
                'correlation_id' => $options->correlationId,
                'idempotency_key' => $options->idempotencyKey,
                'dry_run' => false,
                'engine' => 'graph',
                'input' => $input,
                // Store the canonical graph (unredacted structure) so the queued
                // fan-out join can reload a suspended parent run's graph by id and
                // re-advance it. Same rationale as flow_definitions.graph.
                'graph' => (new GraphSerializer)->toArray($graph),
                'nodes_total' => count($graph->nodeIds()),
                'started_at' => $startedAt,
                'status' => RunState::Running->value,
            ];

            if ($options->replayedFromRunId !== null) {
                $attributes['replayed_from_run_id'] = $options->replayedFromRunId;
            }

            $this->store->runs()->create($attributes);

            foreach ($graph->nodeIds() as $id) {
                $node = $graph->node($id);

                if ($node === null) {
                    continue;
                }

                $this->store->runNodes()->createOrUpdate($runId, $id, [
                    'node_type' => $node->type,
                    'sequence' => $sequenceOf[$id] ?? null,
                    'status' => NodeState::Pending->value,
                    'dry_run_skipped' => false,
                ]);
            }
        });

        return $runId;
    }

    /**
     * Advance the run one pass. Serialized by a `flow_runs` row lock; ready
     * nodes are claimed by compare-and-set. Returns the node ids this pass
     * exclusively claimed and whether EVERY node is terminal. The run is
     * finalized when all nodes are terminal OR when it has settled with no work
     * in flight (nothing claimed, nothing running) — the latter covers a run
     * that stalls on a paused node, which finalizes as `paused` (not terminal).
     */
    public function advance(string $runId, GraphDefinition $graph): CoordinatorDecision
    {
        /** @var list<string> $claimed */
        $claimed = [];
        $allTerminal = false;
        $settled = false;
        $finalized = false;
        $sequenceOf = array_flip($graph->topologicalOrder());

        $this->connection()->transaction(function () use ($runId, $graph, $sequenceOf, &$claimed, &$allTerminal, &$settled, &$finalized): void {
            // Serialize advancement: two coordinators for the same run cannot
            // interleave readiness resolution + claiming.
            $this->connection()->table('flow_runs')->where('id', $runId)->lockForUpdate()->first();

            /** @var array<string, NodeState> $states */
            $states = [];

            // Poison spreads one level per readiness pass, and blocking claims no
            // jobs to re-trigger the coordinator, so drain all newly-blocked
            // nodes here before reading readiness/allTerminal — otherwise a run
            // whose last work is blocked would never finalize.
            while (true) {
                $states = $this->store->runNodes()->states($runId);
                $decision = $this->readiness->resolve($graph, $states);

                if ($decision->blocked !== []) {
                    foreach ($decision->blocked as $id) {
                        $node = $graph->node($id);

                        if ($node === null) {
                            continue;
                        }

                        $this->store->runNodes()->createOrUpdate($runId, $id, [
                            'node_type' => $node->type,
                            'sequence' => $sequenceOf[$id] ?? null,
                            'status' => NodeState::Blocked->value,
                            'dry_run_skipped' => false,
                            'finished_at' => ($this->clock)(),
                        ]);
                    }

                    continue;
                }

                foreach ($decision->ready as $id) {
                    if ($this->store->runNodes()->claim($runId, $id, ($this->clock)())) {
                        $claimed[] = $id;
                        $states[$id] = NodeState::Running;
                    }
                }

                $allTerminal = $decision->allTerminal;

                break;
            }

            // A run with no work in flight (nothing newly claimed, nothing still
            // running) and no ready/blocked work left cannot advance on its own —
            // it has stalled (e.g. it settled on a paused node). Finalize it here
            // so it does not hang as `running` forever waiting for a job that will
            // never be dispatched.
            $running = 0;
            foreach ($states as $state) {
                if ($state === NodeState::Running) {
                    $running++;
                }
            }
            $settled = $claimed === [] && $running === 0;

            // Finalize INSIDE the row lock so duplicate coordinators cannot race
            // to overwrite finished_at/duration_ms/output; finalizeRun() is also
            // idempotent (it no-ops once the run has left `running`).
            if ($allTerminal || $settled) {
                $finalized = $this->finalizeRun($runId, $graph);
            }
        });

        // Graph saga runs OUTSIDE the row lock (compensators are user code that
        // must never hold a `flow_runs` lock) and only on the pass that actually
        // finalized the run — finalizeRun's terminal-state gate guarantees a
        // duplicate coordinator can never double-compensate. Running it BEFORE
        // resumeParentIfChild makes a compensated child report `compensated` to
        // the join ledger in the common case — but this ordering is BEST-EFFORT:
        // under at-least-once delivery a duplicate coordinator (finalized=false,
        // allTerminal=true) can drive the join with the pre-compensation
        // `failed` status while this pass is still compensating. That is
        // acceptable: both are truthful terminal states for the join (the
        // ledger's status is audit detail; the child run row itself still ends
        // `compensated`), and the join fires exactly once either way.
        if ($finalized) {
            $this->compensateIfFailed($runId, $graph);
        }

        // Drive the parent join AFTER the lock is released (so the child's
        // terminal state is committed first) whenever the run is terminal — not
        // only on the finalizing pass. This makes the join re-drivable: if a join
        // block-wait timed out and the coordinator job was retried, the retry
        // (finalized=false, allTerminal=true) re-attempts it. resumeParentIfChild
        // self-gates (not a child / not terminal / already joined) and its
        // completeChild CAS keeps it idempotent.
        if ($finalized || $allTerminal) {
            $this->resumeParentIfChild($runId);
        }

        return new CoordinatorDecision($claimed, $allTerminal);
    }

    /**
     * If the just-finalized run is a spawned child, record its completion in the
     * join ledger; when it is the last child, the join flips the suspended parent
     * node and we re-advance the parent run (reloading its graph from
     * `flow_runs.graph`).
     */
    private function resumeParentIfChild(string $runId): void
    {
        if ($this->join === null || $this->bus === null) {
            return; // no queued-join wiring bound (e.g. a bare coordinator)
        }

        $child = $this->store->runs()->find($runId);

        if ($child === null) {
            return;
        }

        // Only a TERMINAL child drives the join. A child that merely paused (e.g.
        // on a nested queued control node) is not done — recording it would flip
        // the ledger row off `running` and let the outer parent resume before the
        // nested children finish. When the child later reaches a terminal state
        // its finalize re-enters here and drives the join for real.
        $childState = RunState::tryFrom((string) $child->status);

        if ($childState === null || ! $childState->isTerminal()) {
            return;
        }

        $joinResult = $this->join->childCompleted(
            $runId,
            (string) $child->status,
            is_array($child->output) ? $child->output : null,
        );

        if ($joinResult === null) {
            return; // not a child, a duplicate, or not the last sibling
        }

        $parent = $this->store->runs()->find($joinResult->parentRunId);

        if ($parent === null || ! is_array($parent->graph)) {
            return;
        }

        $this->bus->dispatch(new CoordinatorJob(
            runId: $joinResult->parentRunId,
            graph: (new GraphSerializer)->fromArray($parent->graph),
            definitionName: (string) $parent->definition_name,
            input: is_array($parent->input) ? $parent->input : [],
            queue: $this->queue,
            lockStore: $this->lockStore,
            lockSeconds: $this->lockSeconds,
            lockRetrySeconds: $this->lockRetrySeconds,
        ));
    }

    /**
     * Release a claim the coordinator won but could not enqueue a node job for,
     * so a retry re-claims and re-dispatches it (used by {@see CoordinatorJob}
     * when the queue backend throws mid-wave).
     */
    public function releaseClaim(string $runId, string $nodeId): bool
    {
        return $this->store->runNodes()->releaseClaim($runId, $nodeId);
    }

    /**
     * Roll up and persist the run's terminal fields. Finalizes a run in a
     * non-terminal state — `running` OR `paused` (a suspended fan-out/sub-flow
     * parent being resumed by the join). Returns true iff THIS call actually
     * finalized it; false on the idempotent no-op (already terminal) so a
     * duplicate coordinator does not re-drive the parent join.
     */
    private function finalizeRun(string $runId, GraphDefinition $graph): bool
    {
        $run = $this->store->runs()->find($runId);

        // Idempotent: finalize only a run still in a non-terminal state (`running`
        // or `paused` — the latter is a suspended fan-out/sub-flow parent being
        // resumed by the join). Once it reached a terminal RunState a duplicate
        // coordinator must not overwrite its terminal fields.
        $current = $run !== null ? RunState::tryFrom((string) $run->status) : null;

        if ($current === null || $current->isTerminal()) {
            return false;
        }

        $nodes = $this->store->runNodes()->forRun($runId);

        /** @var array<string, NodeState> $states */
        $states = [];
        /** @var array<string, mixed> $output */
        $output = [];

        foreach ($nodes as $row) {
            $states[(string) $row->node_id] = NodeState::from((string) $row->status);

            if ($row->status === NodeState::Succeeded->value && is_array($row->outputs)) {
                $output[(string) $row->node_id] = $row->outputs;
            }
        }

        $runState = RunRollup::state($graph, $states);
        $counters = RunRollup::counters($states);

        $attributes = [
            'status' => $runState->value,
            'output' => $output,
            'nodes_completed' => $counters['completed'],
            'nodes_failed' => $counters['failed'],
        ];

        // A paused run is not finished: keep finished_at / duration_ms null so
        // it is not treated as completed (matching v1's paused-run invariant).
        if ($runState !== RunState::Paused) {
            $finishedAt = ($this->clock)();
            $attributes['finished_at'] = $finishedAt;
            $attributes['duration_ms'] = $this->durationMs($run->started_at, $finishedAt);
        }

        $this->store->runs()->update($runId, $attributes);

        return true;
    }

    /**
     * Trigger the graph saga for a run that just finalized on a failure state.
     * Reads node states/outputs back from persistence (the queued path's only
     * shared channel — under enabled redaction, compensators therefore see the
     * REDACTED outputs, same caveat as inter-node routing). The run is marked
     * `compensated` only on a FULL rollback; a partial one records
     * `compensation_status = 'failed'` and keeps the failure status.
     */
    private function compensateIfFailed(string $runId, GraphDefinition $graph): void
    {
        if ($this->saga === null) {
            return;
        }

        $run = $this->store->runs()->find($runId);

        if ($run === null) {
            return;
        }

        $state = RunState::tryFrom((string) $run->status);

        if (! in_array($state, [RunState::Failed, RunState::PartiallySucceeded], true)) {
            return;
        }

        /** @var array<string, NodeState> $states */
        $states = [];
        /** @var array<string, array<string, mixed>> $outputs */
        $outputs = [];

        foreach ($this->store->runNodes()->forRun($runId) as $row) {
            $states[(string) $row->node_id] = NodeState::from((string) $row->status);

            if ($row->status === NodeState::Succeeded->value && is_array($row->outputs)) {
                $outputs[(string) $row->node_id] = $row->outputs;
            }
        }

        $report = $this->saga->compensate(
            $runId,
            (string) $run->definition_name,
            $graph,
            $states,
            $outputs,
            $this->compensationStrategy,
            is_array($run->input) ? $run->input : [],
            queued: true,
        );

        if (! $report->attempted()) {
            return;
        }

        $attributes = [
            'compensated' => $report->fullySucceeded(),
            'compensation_status' => $report->fullySucceeded() ? 'succeeded' : 'failed',
        ];

        if ($report->fullySucceeded()) {
            $attributes['status'] = RunState::Compensated->value;
        }

        $this->store->runs()->update($runId, $attributes);
    }

    private function durationMs(mixed $startedAt, DateTimeImmutable $finishedAt): ?int
    {
        if (! $startedAt instanceof \DateTimeInterface) {
            return null;
        }

        return (int) round(((float) $finishedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000);
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->connectionName);
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
