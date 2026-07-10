<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\Graph\GraphDefinition;

/**
 * The single place per-node states are rolled up into a {@see RunState} and the
 * `nodes_completed`/`nodes_failed` counters. Shared by the synchronous
 * {@see GraphRunner} and the queued {@see QueueGraphCoordinator} so the two
 * execution paths can never disagree on when a run is done or how it settled.
 *
 * @internal
 */
final class RunRollup
{
    /**
     * @param  array<string, NodeState>  $states
     */
    public static function state(GraphDefinition $graph, array $states): RunState
    {
        $succeeded = 0;
        $poisoned = 0;
        $paused = 0;
        $nonTerminal = 0;

        foreach ($graph->nodeIds() as $id) {
            $state = $states[$id] ?? NodeState::Pending;

            match (true) {
                $state === NodeState::Succeeded => $succeeded++,
                $state === NodeState::Paused => $paused++,
                in_array($state, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true) => $poisoned++,
                $state === NodeState::Skipped => null,
                default => $nonTerminal++,
            };
        }

        if ($paused > 0) {
            return RunState::Paused;
        }

        if ($poisoned === 0 && $nonTerminal === 0) {
            return RunState::Succeeded;
        }

        return $succeeded > 0 ? RunState::PartiallySucceeded : RunState::Failed;
    }

    /**
     * @param  array<string, NodeState>  $states
     * @return array{completed: int, failed: int}
     */
    public static function counters(array $states): array
    {
        $completed = 0;
        $failed = 0;

        foreach ($states as $state) {
            if ($state === NodeState::Succeeded || $state === NodeState::Skipped) {
                $completed++;
            } elseif (in_array($state, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true)) {
                $failed++;
            }
        }

        return ['completed' => $completed, 'failed' => $failed];
    }
}
