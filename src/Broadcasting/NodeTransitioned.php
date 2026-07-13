<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\State\NodeState;

/**
 * Broadcast when a graph node reaches an EXECUTION OUTCOME — not every
 * persisted state along the way. There is no event for the queued
 * coordinator's `pending -> running` claim; this fires once a node's
 * outcome is known: `succeeded`, `failed`, `dead_letter`, `paused`,
 * `skipped`, or `invalid_input` (dispatched from {@see NodeExecutor}'s single
 * persist seam, so the synchronous and queued graph executors can never emit
 * divergent events for a node that actually ran), plus `blocked` — the one
 * outcome that never reaches that seam because the node never attempts a
 * handler, fired instead from `GraphRunner::persistBlocked()` (sync) and the
 * queued coordinator's poison-propagation loop. Never dispatched on a dry run
 * (a simulation has no externally-observable side effects) or when
 * `laravel-flow.broadcasting.enabled` is `false`.
 *
 * The package emits only — it ships NO channel authorization callback; the
 * host application's `routes/channels.php` decides who may subscribe.
 *
 * CONSUMER WARNING: dispatch is wrapped in a broad catch (logged as a
 * warning, never rethrown) so a broadcast-driver failure can never abort
 * node execution — but that catch also swallows an exception thrown by ANY
 * listener you attach to this event. Do not attach a listener whose failure
 * must be surfaced or must abort the run; it will be logged, not propagated.
 *
 * @api
 */
final class NodeTransitioned implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $channelPrefix,
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly string $nodeType,
        public readonly NodeState $state,
        public readonly int $sequence,
        public readonly string $occurredAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("{$this->channelPrefix}.run.{$this->runId}");
    }

    public function broadcastAs(): string
    {
        return 'node.transitioned';
    }

    /**
     * @return array{run_id: string, node_id: string, node_type: string, state: string, sequence: int, occurred_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'node_id' => $this->nodeId,
            'node_type' => $this->nodeType,
            'state' => $this->state->value,
            'sequence' => $this->sequence,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
