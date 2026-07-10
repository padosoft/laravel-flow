<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Graph\GraphDefinition;

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
 * Node execution itself and job dispatch live in the {@see Jobs\CoordinatorJob}
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
     * exclusively claimed and whether the run reached a terminal state.
     */
    public function advance(string $runId, GraphDefinition $graph): CoordinatorDecision
    {
        /** @var list<string> $claimed */
        $claimed = [];
        $allTerminal = false;
        $sequenceOf = array_flip($graph->topologicalOrder());

        $this->connection()->transaction(function () use ($runId, $graph, $sequenceOf, &$claimed, &$allTerminal): void {
            // Serialize advancement: two coordinators for the same run cannot
            // interleave readiness resolution + claiming.
            $this->connection()->table('flow_runs')->where('id', $runId)->lockForUpdate()->first();

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
                    }
                }

                $allTerminal = $decision->allTerminal;

                break;
            }
        });

        if ($allTerminal) {
            $this->finalizeRun($runId, $graph);
        }

        return new CoordinatorDecision($claimed, $allTerminal);
    }

    private function finalizeRun(string $runId, GraphDefinition $graph): void
    {
        $states = $this->store->runNodes()->states($runId);
        $runState = RunRollup::state($graph, $states);
        $counters = RunRollup::counters($states);

        $attributes = [
            'status' => $runState->value,
            'nodes_completed' => $counters['completed'],
            'nodes_failed' => $counters['failed'],
        ];

        // A paused run is not finished: keep finished_at / duration_ms null so
        // it is not treated as completed (matching v1's paused-run invariant).
        if ($runState !== RunState::Paused) {
            $finishedAt = ($this->clock)();
            $attributes['finished_at'] = $finishedAt;
            $attributes['duration_ms'] = $this->durationMs($runId, $finishedAt);
        }

        $this->store->runs()->update($runId, $attributes);
    }

    private function durationMs(string $runId, DateTimeImmutable $finishedAt): ?int
    {
        $run = $this->store->runs()->find($runId);
        $startedAt = $run?->started_at;

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
