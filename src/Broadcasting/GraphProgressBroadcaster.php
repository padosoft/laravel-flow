<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Broadcasting;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;

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

        $this->events->dispatch(new NodeTransitioned(
            $this->channelPrefix,
            $runId,
            $nodeId,
            $nodeType,
            $state,
            $sequence,
            ($this->clock)()->format(DateTimeImmutable::ATOM),
        ));
    }

    public function runProgressUpdated(string $runId, RunState $status, int $nodesTotal, int $nodesCompleted, int $nodesFailed): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->events->dispatch(new GraphRunProgressUpdated(
            $this->channelPrefix,
            $runId,
            $status,
            $nodesTotal,
            $nodesCompleted,
            $nodesFailed,
            ($this->clock)()->format(DateTimeImmutable::ATOM),
        ));
    }
}
