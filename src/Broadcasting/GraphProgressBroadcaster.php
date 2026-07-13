<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Broadcasting;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Throwable;

/**
 * Single dispatch point for graph run/node broadcasting: both {@see NodeExecutor}
 * (node transitions, one seam shared by the sync and queued executors) and
 * each runner's run-finalization path (aggregate snapshot) call into this
 * class rather than constructing/dispatching broadcast events themselves, so
 * the "enabled" gate and payload-building logic live in exactly one place.
 * Uses the SAME event-dispatch idiom as v1's audit events
 * (`Illuminate\Contracts\Events\Dispatcher::dispatch()`), which is what makes
 * `Illuminate\Support\Facades\Event::fake()` observe these dispatches in
 * tests without a real broadcast connection configured.
 *
 * @internal
 */
final class GraphProgressBroadcaster
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly bool $enabled,
        private readonly string $channelPrefix,
        private readonly Closure $clock,
    ) {}

    public function nodeTransitioned(string $runId, string $nodeId, string $nodeType, NodeState $state, int $sequence): void
    {
        if (! $this->enabled) {
            return;
        }

        // Broadcasting is best-effort, like the node cache / approval token
        // issuance elsewhere in NodeExecutor: a throwing broadcast driver must
        // NEVER propagate into the executor. Uncaught here, it would abort
        // persist() before the durable DB write on the queued path — the job
        // then retries and re-executes a handler that already succeeded.
        try {
            $this->events->dispatch(new NodeTransitioned(
                $this->channelPrefix,
                $runId,
                $nodeId,
                $nodeType,
                $state,
                $sequence,
                ($this->clock)()->format(DateTimeImmutable::ATOM),
            ));
        } catch (Throwable $e) {
            // run_id/node_id/state/sequence are non-secret identifiers, unlike
            // an exception MESSAGE (which for e.g. a QueryException can embed
            // bound params) — included so an operator can correlate the
            // failure to a specific execution without exposing anything.
            Log::warning('laravel-flow: node-transition broadcast failed.', [
                'run_id' => $runId,
                'node_id' => $nodeId,
                'node_type' => $nodeType,
                'state' => $state->value,
                'sequence' => $sequence,
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);
        }
    }

    public function runProgressUpdated(string $runId, RunState $status, int $nodesTotal, int $nodesCompleted, int $nodesFailed): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $this->events->dispatch(new GraphRunProgressUpdated(
                $this->channelPrefix,
                $runId,
                $status,
                $nodesTotal,
                $nodesCompleted,
                $nodesFailed,
                ($this->clock)()->format(DateTimeImmutable::ATOM),
            ));
        } catch (Throwable $e) {
            // Same non-secret-identifiers-only discipline as the node-transition
            // log above.
            Log::warning('laravel-flow: run-progress broadcast failed.', [
                'run_id' => $runId,
                'status' => $status->value,
                'nodes_total' => $nodesTotal,
                'nodes_completed' => $nodesCompleted,
                'nodes_failed' => $nodesFailed,
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);
        }
    }
}
