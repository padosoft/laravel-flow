<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Graph\GraphDefinition;

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

        // Root nodes (no incoming wire) receive the run input on the conventional
        // `input` port — this is how the compiled v1 first step reads the flow
        // input, and it is a harmless no-op for graph nodes without an `input`
        // port (the router only reads config for ports the node actually has).
        $hasIncoming = NodeRouting::nodesWithIncoming($graph);

        $this->persistRunStarted($store, $runId, $definitionName, $input, $dryRun, count($graph->nodeIds()), $startedAt, $options);

        /** @var array<string, NodeState> $states */
        $states = [];
        /** @var array<string, array<string, mixed>> $outputs */
        $outputs = [];
        /** @var array<string, string> $errors */
        $errors = [];

        while (true) {
            $decision = $this->readiness->resolve($graph, $states);

            if ($decision->allTerminal) {
                break;
            }

            $progressed = false;

            foreach ($decision->blocked as $id) {
                $states[$id] = NodeState::Blocked;
                $this->persistBlocked($store, $runId, $graph, $id, $sequenceOf[$id] ?? null);
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

                $progressed = true;
            }

            if (! $progressed) {
                break; // safety net: no ready node and nothing new blocked
            }
        }

        $runState = RunRollup::state($graph, $states);
        $this->persistRunFinished($store, $runId, $runState, $states, $outputs, $startedAt);

        return new GraphRunResult($runId, $runState, $states, $outputs, $errors);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persistRunStarted(
        ?FlowStore $store,
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

        if ($options->replayedFromRunId !== null) {
            $attributes['replayed_from_run_id'] = $options->replayedFromRunId;
        }

        $store->runs()->create($attributes);
    }

    private function persistBlocked(?FlowStore $store, string $runId, GraphDefinition $graph, string $nodeId, ?int $sequence): void
    {
        if ($store === null) {
            return;
        }

        $node = $graph->node($nodeId);

        if ($node === null) {
            return;
        }

        $now = ($this->clock)();

        $store->runNodes()->createOrUpdate($runId, $nodeId, [
            'node_type' => $node->type,
            'sequence' => $sequence,
            'status' => NodeState::Blocked->value,
            'dry_run_skipped' => false,
            'finished_at' => $now,
        ]);
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
