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
        private readonly CacheFactory $cache,
        private readonly Closure $clock,
        private readonly ?string $lockStore = null,
        private readonly int $lockSeconds = 10,
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
        $lock->block(max(1, $this->lockSeconds));

        try {
            $finishedAt = ($this->clock)();

            if (! $this->children->completeChild($childRunId, $childStatus, $childOutputs, $finishedAt)) {
                return null; // a duplicate completion; the original drives the join
            }

            $siblings = $this->children->forParent($parentRunId, $parentNodeId);

            foreach ($siblings as $sibling) {
                if ($sibling->status === NodeState::Running->value) {
                    return null; // still-running children remain; not the last one
                }
            }

            return $this->resumeParent($parentRunId, $parentNodeId, $siblings, $finishedAt);
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
        foreach ($this->nodes->forRun($parentRunId) as $node) {
            if ($node->node_id === $parentNodeId) {
                return $node->node_type;
            }
        }

        return 'flow.subflow';
    }
}
