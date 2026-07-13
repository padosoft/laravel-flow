<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Padosoft\LaravelFlow\Executor\NodeExecutor;
use Padosoft\LaravelFlow\Executor\State\NodeState;

/**
 * Broadcast when a graph node transitions to a new persisted state. Dispatched
 * from {@see NodeExecutor}'s single persist seam for every EXECUTED node (so
 * the synchronous and queued graph executors can never emit divergent events
 * for a node that actually ran), plus two additional emission points for
 * nodes that never reach that seam because they never attempt a handler:
 * `GraphRunner::persistBlocked()` (sync) and the queued coordinator's
 * poison-propagation loop, both firing `NodeState::Blocked`. Never dispatched
 * on a dry run (a simulation has no externally-observable side effects) or
 * when `laravel-flow.broadcasting.enabled` is `false`.
 *
 * The package emits only — it ships NO channel authorization callback; the
 * host application's `routes/channels.php` decides who may subscribe.
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
