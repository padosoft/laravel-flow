<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Executor\QueueGraphCoordinator;
use Padosoft\LaravelFlow\Executor\RunRollup;
use Padosoft\LaravelFlow\Executor\State\RunState;

/**
 * Aggregate progress snapshot for a graph run, broadcast on the SAME per-run
 * private channel as {@see NodeTransitioned}. Fired once a run settles into a
 * terminal or paused state (both the synchronous {@see GraphRunner}
 * and the queued {@see QueueGraphCoordinator}
 * compute the SAME counters via {@see RunRollup}
 * before dispatching this event, so the two paths can never disagree on the
 * snapshot). Never dispatched on a dry run or when
 * `laravel-flow.broadcasting.enabled` is `false`.
 *
 * @api
 */
final class GraphRunProgressUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly string $channelPrefix,
        public readonly string $runId,
        public readonly RunState $status,
        public readonly int $nodesTotal,
        public readonly int $nodesCompleted,
        public readonly int $nodesFailed,
        public readonly string $occurredAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("{$this->channelPrefix}.run.{$this->runId}");
    }

    public function broadcastAs(): string
    {
        return 'run.progress';
    }

    /**
     * @return array{run_id: string, status: string, nodes_total: int, nodes_completed: int, nodes_failed: int, progress_pct: float, occurred_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'nodes_total' => $this->nodesTotal,
            'nodes_completed' => $this->nodesCompleted,
            'nodes_failed' => $this->nodesFailed,
            'progress_pct' => $this->progressPct(),
            'occurred_at' => $this->occurredAt,
        ];
    }

    private function progressPct(): float
    {
        if ($this->nodesTotal <= 0) {
            return 0.0;
        }

        return round((($this->nodesCompleted + $this->nodesFailed) / $this->nodesTotal) * 100, 2);
    }
}
