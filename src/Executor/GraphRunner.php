<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;

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
        $hasIncoming = [];
        foreach ($graph->connections as $wire) {
            $hasIncoming[$wire->targetNodeId] = true;
        }

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

                $node = $this->seedRootInput($node, ! isset($hasIncoming[$id]), $input);

                $execution = $this->executor->execute(
                    $runId,
                    $definitionName,
                    $node,
                    $this->connectionsInto($graph, $id),
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

        $runState = $this->rollUp($graph, $states);
        $this->persistRunFinished($store, $runId, $runState, $states, $outputs, $startedAt);

        return new GraphRunResult($runId, $runState, $states, $outputs, $errors);
    }

    /**
     * @param  array<string, NodeState>  $states
     */
    private function rollUp(GraphDefinition $graph, array $states): RunState
    {
        $succeeded = 0;
        $poisoned = 0;
        $paused = 0;
        $nonTerminal = 0;

        foreach ($graph->nodeIds() as $id) {
            $state = $states[$id] ?? NodeState::Pending;

            match (true) {
                $state === NodeState::Succeeded => $succeeded++,
                $state === NodeState::Paused => $paused++,
                in_array($state, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true) => $poisoned++,
                $state === NodeState::Skipped => null,
                default => $nonTerminal++,
            };
        }

        if ($paused > 0) {
            return RunState::Paused;
        }

        if ($poisoned === 0 && $nonTerminal === 0) {
            return RunState::Succeeded;
        }

        return $succeeded > 0 ? RunState::PartiallySucceeded : RunState::Failed;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function seedRootInput(GraphNode $node, bool $isRoot, array $input): GraphNode
    {
        if (! $isRoot || array_key_exists('input', $node->config)) {
            return $node;
        }

        return new GraphNode($node->id, $node->type, ['input' => $input] + $node->config, $node->position);
    }

    /**
     * @return list<Connection>
     */
    private function connectionsInto(GraphDefinition $graph, string $nodeId): array
    {
        return array_values(array_filter(
            $graph->connections,
            static fn (Connection $c): bool => $c->targetNodeId === $nodeId,
        ));
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

        $finishedAt = ($this->clock)();
        $completed = 0;
        $failed = 0;

        foreach ($states as $state) {
            if ($state === NodeState::Succeeded || $state === NodeState::Skipped) {
                $completed++;
            } elseif (in_array($state, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true)) {
                $failed++;
            }
        }

        $store->runs()->update($runId, [
            'status' => $runState->value,
            'output' => $outputs,
            'nodes_completed' => $completed,
            'nodes_failed' => $failed,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) round(((float) $finishedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000),
        ]);
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
