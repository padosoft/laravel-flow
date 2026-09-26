<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Padosoft\LaravelFlow\Broadcasting\GraphProgressBroadcaster;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\TimerRepository;
use Padosoft\LaravelFlow\Executor\Jobs\CoordinatorJob;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Node\NodeResult;

/**
 * Completes a due timer node ({@see NodeResult::pausedUntil()}) and re-enters
 * the SAME {@see CoordinatorJob} every other queued advancement uses, so a
 * resumed timer inherits readiness, claiming, finalize and saga behaviour
 * unchanged — exactly as {@see GraphApprovalCoordinator} does for an approval.
 *
 * The whole decision runs under the run's `flow_runs` row lock (the same lock
 * {@see QueueGraphCoordinator::advance()} takes), so it serialises with
 * finalize, and every guard is a no-op rather than an error: a duplicate job, a
 * late job after a cancel, a node that is not a paused timer, or a run that has
 * already ended all end in {@see TimerResumeOutcome::noop()}. The node flip is
 * a compare-and-set in {@see TimerRepository::resumeTimer()}, so at most one
 * caller ever flips it. A caller that finds a timer ALREADY flipped re-enters
 * the coordinator instead of stopping, so a transient failure to enqueue it
 * (after the flip committed) is recovered by the job's own retry.
 *
 * @internal
 */
final class TimerResumer
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly FlowStore $store,
        private readonly Closure $clock,
        private readonly BusDispatcher $bus,
        private readonly ?string $connectionName = null,
        private readonly ?string $queue = null,
        private readonly ?string $lockStore = null,
        private readonly int $lockSeconds = 3600,
        private readonly int $lockRetrySeconds = 30,
        private readonly ?GraphProgressBroadcaster $progressBroadcaster = null,
    ) {}

    public function resume(string $runId, string $nodeId): TimerResumeOutcome
    {
        $timers = $this->store->runNodes();

        if (! $timers instanceof TimerRepository) {
            return TimerResumeOutcome::noop();
        }

        $outcome = TimerResumeOutcome::noop();
        $dispatch = null;
        $announce = false;
        $sequence = 0;
        $nodeType = '';

        $this->connection()->transaction(function () use ($timers, $runId, $nodeId, &$outcome, &$dispatch, &$announce, &$sequence, &$nodeType): void {
            $this->connection()->table('flow_runs')->where('id', $runId)->lockForUpdate()->first();

            $run = $this->store->runs()->find($runId);

            if ($run === null || ! is_array($run->graph) || $run->graph === []) {
                return;
            }

            $runState = RunState::tryFrom((string) $run->status);

            // A finished or cancelled run never resumes a timer.
            if ($runState === null || $runState->isTerminal()) {
                return;
            }

            $dueAt = $timers->pendingTimer($runId, $nodeId);

            if ($dueAt === null) {
                // No pending timer. If the node is a timer that an EARLIER attempt
                // already completed, that attempt may have committed the flip and
                // then failed to enqueue the coordinator (a transient queue
                // outage): the run would be stuck for good, because nothing else
                // ever advances it. Re-driving is safe — the coordinator is
                // idempotent (claims are compare-and-set) — so a retry of the
                // job, or a duplicate, just re-enters it. Anything else (a
                // cancelled node, an approval pause, an unknown node) is a no-op.
                if (! $timers->isResumedTimer($runId, $nodeId)) {
                    return;
                }
            } else {
                $now = ($this->clock)();

                if ($dueAt > $now) {
                    $outcome = TimerResumeOutcome::notDue($dueAt);

                    return;
                }

                if (! $timers->resumeTimer($runId, $nodeId, $now)) {
                    return;
                }

                // Only the attempt that actually flipped the node announces it.
                $announce = true;
            }

            // A run finalized as `paused` (nothing else in flight) goes back to
            // `running`. A run that is still `running` because a parallel
            // branch is executing is left alone — the coordinator advances it.
            if ($runState === RunState::Paused) {
                $runs = $this->store->runs();

                if ($runs instanceof ConditionalRunRepository) {
                    $runs->updateWhereStatus($runId, RunState::Paused->value, ['status' => RunState::Running->value]);
                } else {
                    $runs->update($runId, ['status' => RunState::Running->value]);
                }
            }

            $graph = (new GraphSerializer)->fromArray($run->graph);
            $position = array_search($nodeId, $graph->topologicalOrder(), true);
            $sequence = $position === false ? 0 : (int) $position;
            $nodeType = $graph->node($nodeId)->type ?? '';

            $dispatch = new CoordinatorJob(
                runId: $runId,
                graph: $graph,
                definitionName: (string) $run->definition_name,
                input: is_array($run->input) ? $run->input : [],
                queue: $this->queue,
                lockStore: $this->lockStore,
                lockSeconds: $this->lockSeconds,
                lockRetrySeconds: $this->lockRetrySeconds,
            );
            $outcome = TimerResumeOutcome::resumed();
        });

        // Announce and dispatch AFTER the lock is released and the flip is
        // committed: a subscriber never sees a transition before it is durable,
        // and a coordinator never runs against an uncommitted node row.
        if ($dispatch instanceof CoordinatorJob) {
            if ($announce) {
                $this->progressBroadcaster?->nodeTransitioned($runId, $nodeId, $nodeType, NodeState::Succeeded, $sequence);
            }

            // If this throws, the flip is already committed; the job retry (or a
            // duplicate) lands in the re-drive branch above rather than a no-op.
            $this->bus->dispatch($dispatch);
        }

        return $outcome;
    }

    private function connection(): ConnectionInterface
    {
        return $this->connections->connection($this->connectionName);
    }
}
