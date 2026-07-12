<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\Nodes\ApprovalGateNode;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;

/**
 * Bridges an approved/rejected {@see FlowApprovalRecord}
 * decision (already consumed by {@see FlowEngine}'s
 * generic, engine-agnostic approval machinery) onto a PAUSED graph run: it
 * mutates the paused {@see ApprovalGateNode}'s persisted row directly (there is
 * no "attempt" to run — the decision itself IS the node's outcome), flips the
 * run back to `Running`, and dispatches the SAME {@see CoordinatorJob} used
 * for ordinary queued advancement — reusing 100% of the existing readiness
 * resolution, per-node claiming, finalize, and (on reject) {@see GraphSaga}
 * compensation machinery, exactly as a suspended fan-out parent is resumed by
 * {@see JoinCoordinator}.
 *
 * `queue.default=sync` makes the dispatched job run inline, so `resume()`/
 * `reject()` observe the fully-advanced (or next-paused, or compensated) run
 * by the time they return; under a real queue the caller sees the immediate
 * `running` transition and the advancement continues asynchronously — same
 * duality as every other queued graph entry point in this package.
 *
 * @internal
 */
final class GraphApprovalCoordinator
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly FlowStore $store,
        private readonly Closure $clock,
        private readonly BusDispatcher $bus,
        private readonly ?string $queue = null,
        private readonly ?string $lockStore = null,
        private readonly int $lockSeconds = 3600,
        private readonly int $lockRetrySeconds = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resume(FlowRunRecord $run, string $nodeId, array $payload): FlowRunRecord
    {
        return $this->advance($run, $nodeId, NodeState::Succeeded, ['out' => $payload], null, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reject(FlowRunRecord $run, string $nodeId, array $payload): FlowRunRecord
    {
        return $this->advance($run, $nodeId, NodeState::Failed, null, FlowExecutionException::class, 'Approval gate rejected.');
    }

    /**
     * @param  array<string, mixed>|null  $outputs
     */
    private function advance(
        FlowRunRecord $run,
        string $nodeId,
        NodeState $terminalState,
        ?array $outputs,
        ?string $errorClass,
        ?string $errorMessage,
    ): FlowRunRecord {
        $now = ($this->clock)();
        $graph = (new GraphSerializer)->fromArray(is_array($run->graph) ? $run->graph : []);
        $node = $graph->node($nodeId);

        if ($node === null) {
            throw new FlowExecutionException(sprintf('Approval gate node [%s] was not found in run [%s]\'s graph.', $nodeId, $run->id));
        }

        $sequence = array_search($nodeId, $graph->topologicalOrder(), true);

        // The decision IS the node's outcome — no handler attempt to run.
        // `node_type`/`sequence` are RE-SUPPLIED even though the row already
        // exists: the upsert's underlying INSERT clause must satisfy every
        // NOT NULL column regardless of whether it resolves to an update.
        // Explicitly clear error/backoff fields (same stale-field-clearing
        // discipline as a cache-hit persist): this row's only prior status was
        // `paused`, so they should already be null, but a defensive clear
        // costs nothing and matches this package's established pattern.
        $this->store->runNodes()->createOrUpdate($run->id, $nodeId, [
            'node_type' => $node->type,
            'sequence' => $sequence !== false ? $sequence : 0,
            'status' => $terminalState->value,
            'outputs' => $outputs,
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
            'available_at' => null,
            'finished_at' => $now,
        ]);

        // A run that started SYNCHRONOUSLY (GraphRunner) only ever persisted a
        // row for a node once it actually became ready — unlike a run started
        // via QueueGraphCoordinator::start(), which pre-seeds every node
        // `pending` up front. The coordinator's claim() is a CAS keyed on an
        // EXISTING `pending` row, so any node this run never reached has no
        // row to claim and would be silently skipped. Seed one here — this is
        // exactly the seam where a sync-started run is handed off to the
        // queued advancement machinery for the first time.
        $this->seedPendingRows($run->id, $graph);

        // `Paused -> Running` is the documented resume edge (RunState::
        // canTransitionTo). CAS when the repository supports it (defense in
        // depth — the caller already holds the run-id-keyed approval-decision
        // lock, which is the actual mutual-exclusion primitive here); fall
        // back to a plain update for custom backends that only implement the
        // base RunRepository contract.
        $runs = $this->store->runs();

        if ($runs instanceof ConditionalRunRepository) {
            $runs->updateWhereStatus($run->id, RunState::Paused->value, ['status' => RunState::Running->value]);
        } else {
            $runs->update($run->id, ['status' => RunState::Running->value]);
        }

        $this->bus->dispatch(new CoordinatorJob(
            runId: $run->id,
            graph: $graph,
            definitionName: (string) $run->definition_name,
            input: is_array($run->input) ? $run->input : [],
            queue: $this->queue,
            lockStore: $this->lockStore,
            lockSeconds: $this->lockSeconds,
            lockRetrySeconds: $this->lockRetrySeconds,
        ));

        return $this->store->runs()->find($run->id) ?? $run;
    }

    private function seedPendingRows(string $runId, GraphDefinition $graph): void
    {
        $existing = [];

        foreach ($this->store->runNodes()->forRun($runId) as $row) {
            $existing[(string) $row->node_id] = true;
        }

        $sequenceOf = array_flip($graph->topologicalOrder());

        foreach ($graph->nodes as $node) {
            if (isset($existing[$node->id])) {
                continue;
            }

            $this->store->runNodes()->createOrUpdate($runId, $node->id, [
                'node_type' => $node->type,
                'sequence' => $sequenceOf[$node->id] ?? 0,
                'status' => NodeState::Pending->value,
                'dry_run_skipped' => false,
            ]);
        }
    }
}
