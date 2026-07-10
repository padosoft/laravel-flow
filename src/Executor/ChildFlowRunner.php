<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\NodeChildRepository;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use RuntimeException;

/**
 * Shared child-run primitive for the fan-out / sub-flow control nodes: loads a
 * published child flow and runs each child inline (synchronous executor) or
 * spawns pending children as their own queued runs, recording a
 * {@see NodeChildRepository} ledger row in both paths so a sub-flow is visible
 * for audit/Dashboard.
 *
 * @internal
 */
final class ChildFlowRunner
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly DefinitionRepository $definitions,
        private readonly FlowEngine $engine,
        private readonly NodeChildRepository $children,
        private readonly FlowStore $store,
        private readonly Closure $clock,
    ) {}

    public function loadGraph(string $flow, ?int $version): GraphDefinition
    {
        $stored = $version !== null
            ? $this->definitions->find($flow, $version)
            : $this->definitions->latest($flow, 'published');

        if ($stored === null) {
            throw new RuntimeException("No published version of flow [{$flow}] is available to run as a child.");
        }

        return (new GraphSerializer)->fromArray($stored->graph);
    }

    /**
     * Record an item as a `pending` child (no run spawned yet).
     *
     * @param  array<string, mixed>  $input
     */
    public function recordPending(string $parentRunId, string $parentNodeId, int $childIndex, string $flow, ?int $version, array $input): void
    {
        $this->children->recordPending($parentRunId, $parentNodeId, $childIndex, $flow, $version, $input);
    }

    /**
     * Spawn the next still-`pending` child (windowing): claim its ledger row
     * (`pending` -> `running`) and create its run. Returns false when no pending
     * item remains. Mutual exclusion between the two call sites (the control
     * node's initial burst and the join's release) comes from the atomic
     * {@see NodeChildRepository::claimNextPending()} claim inside the spawn
     * transaction — NOT from any cache lock — so two spawners can never take the
     * same slot even without caller-side serialization.
     */
    public function spawnNext(string $parentRunId, string $parentNodeId, ?GraphDefinition $graph = null): bool
    {
        return $this->store->transaction(fn (): bool => $this->claimAndDispatch($parentRunId, $parentNodeId, $graph));
    }

    /**
     * Spawn the next pending child ONLY while fewer than `maxConcurrency` are
     * already `running` — used by the control node's initial burst so a retried
     * execution (which re-runs the burst) never pushes in-flight children past
     * the cap. The join's {@see self::spawnNext()} needs no cap: it releases one
     * child per completion (1-for-1 under the join lock), which keeps in-flight
     * at the cap.
     */
    public function spawnNextIfUnderCap(string $parentRunId, string $parentNodeId, int $maxConcurrency, ?GraphDefinition $graph = null): bool
    {
        return $this->store->transaction(function () use ($parentRunId, $parentNodeId, $maxConcurrency, $graph): bool {
            if ($this->children->countRunning($parentRunId, $parentNodeId) >= $maxConcurrency) {
                return false;
            }

            return $this->claimAndDispatch($parentRunId, $parentNodeId, $graph);
        });
    }

    /**
     * Claim the next pending row (`pending` -> `running`) BEFORE dispatching, so
     * two concurrent spawners can never dispatch the same slot; the child run +
     * ledger activation commit together (a dispatch failure rolls the claim back
     * to pending), and the child's after-commit coordinator cannot drive the join
     * before the ledger row is committed. MUST run inside a transaction.
     */
    private function claimAndDispatch(string $parentRunId, string $parentNodeId, ?GraphDefinition $graph): bool
    {
        $claimed = $this->children->claimNextPending($parentRunId, $parentNodeId, ($this->clock)());

        if ($claimed === null) {
            return false;
        }

        $graph ??= $this->loadGraph($claimed->child_flow, $claimed->child_version);
        $input = is_array($claimed->input) ? $claimed->input : [];

        $childRunId = $this->engine->dispatchGraph($graph, $input, null, $claimed->child_flow);
        $this->children->attachChildRun($parentRunId, $parentNodeId, $claimed->child_index, $childRunId);

        return true;
    }

    /**
     * Run a child flow inline (synchronous path): record it pending, run it, and
     * complete its ledger row. Returns the child's per-node output map and
     * whether it fully succeeded.
     *
     * @param  array<string, mixed>  $input
     * @return array{output: array<string, mixed>, succeeded: bool}
     */
    public function runInline(string $parentRunId, string $parentNodeId, GraphDefinition $graph, string $flow, ?int $version, array $input, int $childIndex): array
    {
        $this->children->recordPending($parentRunId, $parentNodeId, $childIndex, $flow, $version, $input);
        $this->children->claimNextPending($parentRunId, $parentNodeId, ($this->clock)());

        $result = $this->engine->runGraph($graph, $input, null, $flow);
        $now = ($this->clock)();

        $this->children->attachChildRun($parentRunId, $parentNodeId, $childIndex, $result->runId);
        $this->children->completeChild($result->runId, $result->state->value, $result->nodeOutputs, $now);

        return [
            'output' => $result->nodeOutputs,
            'succeeded' => $result->state === RunState::Succeeded,
        ];
    }
}
