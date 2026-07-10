<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\NodeRouting;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use RuntimeException;

/**
 * Executes a single graph node that a {@see CoordinatorJob} has claimed, then
 * re-dispatches the coordinator to advance the run.
 *
 * Idempotency is DB-derived: the terminal `flow_run_nodes.status` row IS the
 * completion marker (there is no separate cache marker, so there is no window
 * between "row written" and "marker written"). A per-node cache lock serializes
 * execution so duplicate delivery never runs the handler twice; a duplicate
 * that cannot take the lock releases for retry without executing, and a
 * duplicate that finds the node already terminal skips execution. A node still
 * `running` whose lock is HELD is an in-flight/orphaned node — the recovery
 * attempt cannot take the lock and does not re-enter the handler; genuine
 * dead-worker recovery happens only once the lock TTL frees the lock (taking it
 * is the proof the prior executor is gone). Node handlers must therefore be
 * idempotent, exactly as v1 queued flows require.
 *
 * @internal
 */
final class NodeJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $runId,
        public string $nodeId,
        public GraphDefinition $graph,
        public string $definitionName,
        public array $input,
        ?string $queue = null,
        public ?string $lockStore = null,
        public int $lockSeconds = 3600,
        public int $lockRetrySeconds = 30,
    ) {
        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(
        NodeExecutor $executor,
        FlowStore $store,
        CacheFactory $cache,
        ConfigRepository $config,
        BusDispatcher $bus,
    ): void {
        $repository = $cache->store($this->lockStore);
        $lockStore = $repository->getStore();

        if ($lockStore instanceof ArrayStore && ! $this->allowsProcessLocalLocks($config)) {
            throw new RuntimeException('Laravel Flow queued execution requires a shared cache lock store; the array store is process-local.');
        }

        if (! ($lockStore instanceof LockProvider)) {
            throw new RuntimeException('Laravel Flow queued execution requires a cache store that supports atomic locks.');
        }

        // The terminal node row is the completion marker: a duplicate that
        // arrives after this node finished must not re-execute, but still nudges
        // the coordinator so a crash between "row written" and "coordinator
        // dispatched" cannot strand the run.
        if ($this->nodeIsTerminal($store)) {
            $this->dispatchCoordinator($bus);

            return;
        }

        $lock = $lockStore->lock($this->lockKey(), $this->lockSeconds());

        if (! $lock->get()) {
            // Another worker owns this node (or an orphaned run still holds the
            // lock within its TTL): release for retry without executing and let
            // the owner advance the run.
            $this->release($this->lockRetrySeconds());

            return;
        }

        try {
            if ($this->nodeIsTerminal($store)) {
                $this->dispatchCoordinator($bus);

                return;
            }

            $executor->execute(
                $this->runId,
                $this->definitionName,
                $this->nodeFor(),
                NodeRouting::connectionsInto($this->graph, $this->nodeId, $this->sequenceOf()),
                $this->upstreamOutputs($store),
                false,
                $this->sequenceOf()[$this->nodeId] ?? 0,
                $store,
            );

            $this->dispatchCoordinator($bus);
        } finally {
            $lock->release();
        }
    }

    public function lockKey(): string
    {
        return 'laravel-flow:node:'.$this->runId.':'.$this->nodeId;
    }

    /**
     * @phpstan-impure The persisted node state changes as other workers advance the run.
     */
    private function nodeIsTerminal(FlowStore $store): bool
    {
        $state = $store->runNodes()->states($this->runId)[$this->nodeId] ?? NodeState::Pending;

        return $state->isTerminal();
    }

    private function nodeFor(): GraphNode
    {
        $node = $this->graph->node($this->nodeId);

        if ($node === null) {
            throw new RuntimeException("Laravel Flow queued node '{$this->nodeId}' is not part of its run graph.");
        }

        $isRoot = ! isset(NodeRouting::nodesWithIncoming($this->graph)[$this->nodeId]);

        return NodeRouting::seedRootInput($node, $isRoot, $this->input);
    }

    /**
     * Upstream node outputs, read back from persistence (each node runs in its
     * own job, so this is the only shared channel). Under enabled redaction the
     * stored outputs are redacted, so a queued downstream node routes off the
     * redacted value — consistent with "read DTOs return whatever is stored".
     * Redaction is off by default, so queued routing is byte-exact.
     *
     * @return array<string, array<string, mixed>>
     */
    private function upstreamOutputs(FlowStore $store): array
    {
        /** @var array<string, array<string, mixed>> $outputs */
        $outputs = [];

        foreach ($store->runNodes()->forRun($this->runId) as $row) {
            if (is_array($row->outputs)) {
                $outputs[(string) $row->node_id] = $row->outputs;
            }
        }

        return $outputs;
    }

    /**
     * @return array<string, int>
     */
    private function sequenceOf(): array
    {
        return array_flip($this->graph->topologicalOrder());
    }

    private function dispatchCoordinator(BusDispatcher $bus): void
    {
        $bus->dispatch(new CoordinatorJob(
            runId: $this->runId,
            graph: $this->graph,
            definitionName: $this->definitionName,
            input: $this->input,
            queue: $this->queue,
            lockStore: $this->lockStore,
            lockSeconds: $this->lockSeconds,
            lockRetrySeconds: $this->lockRetrySeconds,
        ));
    }

    private function lockSeconds(): int
    {
        return max(1, $this->lockSeconds);
    }

    private function lockRetrySeconds(): int
    {
        return max(1, min($this->lockSeconds(), $this->lockRetrySeconds));
    }

    private function allowsProcessLocalLocks(ConfigRepository $config): bool
    {
        $connection = $this->job?->getConnectionName();

        if (! is_string($connection) || $connection === '') {
            $connection = $config->get('queue.default');
        }

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return $config->get('queue.connections.'.$connection.'.driver') === 'sync';
    }
}
