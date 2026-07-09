<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\State;

use Padosoft\LaravelFlow\Console\PruneFlowRunsCommand;

/**
 * Run-level lifecycle state, and the single source of truth for the persisted
 * `flow_runs.status` string of both engines. Each case value matches the exact
 * string v1 already persisted for the statuses it shares; `partially_succeeded`
 * and `dead_letter` are new graph-executor states that extend the vocabulary
 * without changing any v1 run status string.
 *
 * `isTerminal()` means the run has reached a stopping point for its primary
 * execution — v1 independently persists `'failed'` as a final run status
 * (see {@see PruneFlowRunsCommand}'s terminal
 * list: succeeded, failed, compensated, aborted), not merely a stepping stone
 * toward `'compensated'`. Terminal-ness is therefore ORTHOGONAL to
 * {@see self::canTransitionTo()}'s optional `Failed`/`PartiallySucceeded` ->
 * `Compensated` edge: a run can sit at `Failed` forever (nothing to
 * compensate, or compensation itself failed) and still be a valid, prunable
 * terminal state; a saga rollback that later succeeds is an OPTIONAL
 * follow-up transition from an already-terminal state, not a requirement to
 * leave it.
 *
 * @api
 */
enum RunState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Succeeded = 'succeeded';
    case PartiallySucceeded = 'partially_succeeded';
    case Failed = 'failed';
    case Compensated = 'compensated';
    case Aborted = 'aborted';
    case DeadLetter = 'dead_letter';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::PartiallySucceeded, self::Compensated, self::Aborted, self::DeadLetter => true,
            self::Pending, self::Running, self::Paused => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to === self::Running,
            self::Running => in_array($to, [self::Paused, self::Succeeded, self::PartiallySucceeded, self::Failed, self::Aborted, self::DeadLetter], true),
            self::Paused => in_array($to, [self::Running, self::Failed, self::Aborted], true),
            self::Failed, self::PartiallySucceeded => $to === self::Compensated, // optional follow-up from an already-terminal state
            self::Succeeded, self::Compensated, self::Aborted, self::DeadLetter => false, // terminal, no further transitions at all
        };
    }

    public function transitionTo(self $to): self
    {
        if (! $this->canTransitionTo($to)) {
            throw IllegalStateTransitionException::for('RunState', $this->value, $to->value);
        }

        return $to;
    }
}
