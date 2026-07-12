<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Padosoft\LaravelFlow\Contracts\NodeChildRepository;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Models\FlowNodeChildRecord;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use RuntimeException;

/**
 * Resumes a suspended fan-out/sub-flow parent node EXACTLY once when its last
 * child run terminates. A child completion, under a per-parent cache lock,
 * conditionally records itself (compare-and-set) and counts the still-running
 * siblings; only the completion that finds zero remaining flips the parent node
 * (`paused -> succeeded/failed`) with the ordered per-child output list and
 * returns a {@see JoinResult} for the caller to advance the parent run. The lock
 * + CAS make "last one wins" deterministic under concurrent child completions.
 *
 * @internal
 */
final class JoinCoordinator
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly NodeChildRepository $children,
        private readonly RunNodeRepository $nodes,
        private readonly ChildFlowRunner $childRunner,
        private readonly CacheFactory $cache,
        private readonly Closure $clock,
        private readonly ?string $lockStore = null,
        // The join's critical section is a handful of fast DB queries, so it uses
        // its OWN small lock TTL — deliberately NOT the (legitimately long)
        // node-execution lock_seconds — and a short, bounded block wait so a
        // contended worker retries rather than parking for the whole TTL.
        private readonly int $lockSeconds = 30,
        private readonly int $blockSeconds = 5,
    ) {}

    /**
     * Record a terminating child run and, if it is the last, resume the parent.
     *
     * @param  array<string, mixed>|null  $childOutputs
     */
    public function childCompleted(string $childRunId, string $childStatus, ?array $childOutputs): ?JoinResult
    {
        $child = $this->children->findByChildRun($childRunId);

        if ($child === null) {
            return null; // not a spawned child of any parent node
        }

        $parentRunId = $child->run_id;
        $parentNodeId = $child->parent_node_id;

        $store = $this->cache->store($this->lockStore)->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Laravel Flow fan-out join requires a cache store that supports atomic locks.');
        }

        $lock = $store->lock('laravel-flow:join:'.$parentRunId.':'.$parentNodeId, $this->lockSeconds);
        // Bounded wait: on timeout this throws, the caller's coordinator job is
        // retried, and the re-driven join (idempotent via the CAS steps) tries
        // again — a worker never parks for the full TTL under contention.
        $lock->block(max(1, $this->blockSeconds));

        try {
            $finishedAt = ($this->clock)();

            if (! $this->children->completeChild($childRunId, $childStatus, $childOutputs, $finishedAt)) {
                // A duplicate completion of an ALREADY-driven join. If the
                // ORIGINAL call's resumeParent() succeeded (flipped the
                // parent's fan-out/sub-flow node terminal) but the caller
                // then crashed BEFORE dispatching a coordinator job for the
                // now-resumable parent run, nothing else ever re-triggers
                // that dispatch — the parent run is stuck non-terminal
                // forever. Recover by re-deriving the JoinResult from the
                // parent NODE's durable persisted state (not this call's
                // one-shot CAS outcome) so the caller retries the dispatch;
                // safe to return redundantly because a coordinator
                // dispatch/advance is itself idempotent.
                return $this->parentIfAlreadyResumed($parentRunId, $parentNodeId);
            }

            // Windowing: release the next pending item now that a running slot
            // freed up, keeping in-flight children capped at maxConcurrency.
            $this->childRunner->spawnNext($parentRunId, $parentNodeId);

            if ($this->children->countUnfinished($parentRunId, $parentNodeId) > 0) {
                return null; // pending or still-running children remain
            }

            return $this->resumeParent($parentRunId, $parentNodeId, $this->children->forParent($parentRunId, $parentNodeId), $finishedAt);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  iterable<FlowNodeChildRecord>  $siblings
     */
    private function resumeParent(string $parentRunId, string $parentNodeId, iterable $siblings, DateTimeImmutable $finishedAt): JoinResult
    {
        /** @var list<mixed> $outputs */
        $outputs = [];
        $failed = false;

        foreach ($siblings as $sibling) {
            $outputs[] = $sibling->outputs;

            if ($sibling->status !== 'succeeded') {
                $failed = true;
            }
        }

        $state = $failed ? NodeState::Failed : NodeState::Succeeded;

        $this->nodes->createOrUpdate($parentRunId, $parentNodeId, [
            'node_type' => $this->parentNodeType($parentRunId, $parentNodeId),
            'status' => $state->value,
            'outputs' => ['results' => $outputs],
            'finished_at' => $finishedAt,
        ]);

        return new JoinResult($parentRunId, $parentNodeId, $state, $outputs);
    }

    private function parentNodeType(string $parentRunId, string $parentNodeId): string
    {
        $node = $this->parentNode($parentRunId, $parentNodeId);

        return $node === null ? 'flow.subflow' : $node->node_type;
    }

    /**
     * @return JoinResult|null a reconstructed result if the parent node is
     *                         ALREADY terminal (a prior resumeParent() ran),
     *                         null if it is still pending/running (a genuine
     *                         concurrent duplicate — the in-flight original
     *                         call drives the join)
     */
    private function parentIfAlreadyResumed(string $parentRunId, string $parentNodeId): ?JoinResult
    {
        $parentNode = $this->parentNode($parentRunId, $parentNodeId);

        if ($parentNode === null) {
            return null;
        }

        $state = NodeState::tryFrom((string) $parentNode->status);

        if ($state !== NodeState::Succeeded && $state !== NodeState::Failed) {
            return null;
        }

        $outputs = $parentNode->outputs;
        /** @var list<mixed> $results */
        $results = is_array($outputs) && is_array($outputs['results'] ?? null) ? $outputs['results'] : [];

        return new JoinResult($parentRunId, $parentNodeId, $state, $results);
    }

    private function parentNode(string $parentRunId, string $parentNodeId): ?FlowRunNodeRecord
    {
        foreach ($this->nodes->forRun($parentRunId) as $node) {
            if ($node->node_id === $parentNodeId) {
                return $node;
            }
        }

        return null;
    }
}
