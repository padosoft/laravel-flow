<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Throwable;

/**
 * Advances a queued graph run one pass: it asks the {@see QueueGraphCoordinator}
 * to resolve readiness, mark blocked nodes and atomically claim ready ones
 * (serialized by a run row lock), then dispatches one {@see NodeJob} per node it
 * exclusively claimed. Duplicate delivery is safe — the coordinator's
 * compare-and-set means a re-run claims nothing already claimed and dispatches
 * zero duplicate node jobs; when every node is terminal the coordinator
 * finalizes the run and no further jobs are dispatched.
 *
 * @internal
 */
final class CoordinatorJob implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $runId,
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

    public function handle(QueueGraphCoordinator $coordinator, BusDispatcher $bus): void
    {
        $decision = $coordinator->advance($this->runId, $this->graph);
        $dispatched = 0;

        try {
            foreach ($decision->claimed as $nodeId) {
                $bus->dispatch(new NodeJob(
                    runId: $this->runId,
                    nodeId: $nodeId,
                    graph: $this->graph,
                    definitionName: $this->definitionName,
                    input: $this->input,
                    queue: $this->queue,
                    lockStore: $this->lockStore,
                    lockSeconds: $this->lockSeconds,
                    lockRetrySeconds: $this->lockRetrySeconds,
                ));
                $dispatched++;
            }
        } catch (Throwable $e) {
            // The claims committed (pending -> running) but a node job could not
            // be enqueued; release the claims we did not dispatch so a retry of
            // this coordinator re-claims and re-dispatches them instead of
            // leaving those nodes stuck `running` with no job in flight.
            foreach (array_slice($decision->claimed, $dispatched) as $nodeId) {
                $coordinator->releaseClaim($this->runId, $nodeId);
            }

            throw $e;
        }
    }
}
