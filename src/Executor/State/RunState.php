<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\State;

/**
 * Run-level lifecycle state, and the single source of truth for the persisted
 * `flow_runs.status` string of both engines. Each case value matches the exact
 * string v1 already persisted for the statuses it shares; `partially_succeeded`
 * and `dead_letter` are new graph-executor states that extend the vocabulary
 * without changing any v1 run status string.
 *
 * `isTerminal()` answers "can this state still make a legal transition?", not
 * "is this run finished?". `Failed` and `PartiallySucceeded` are non-terminal
 * because a saga rollback may still transition them to `Compensated` — this
 * mirrors v1's real in-memory lifecycle, where a compensating run runs
 * `markFailed()` (→ `failed`) and then `markCompensated()` (→ `compensated`).
 * A failed run with no compensators simply never transitions again and stays
 * `failed`; consumers deciding whether a run is finished should treat a
 * `Failed`/`PartiallySucceeded` run with no pending compensation as done
 * rather than relying on `isTerminal()` alone.
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
            self::Succeeded, self::Compensated, self::Aborted, self::DeadLetter => true,
            self::Pending, self::Running, self::Paused, self::Failed, self::PartiallySucceeded => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to === self::Running,
            self::Running => in_array($to, [self::Paused, self::Succeeded, self::PartiallySucceeded, self::Failed, self::Aborted, self::DeadLetter], true),
            self::Paused => in_array($to, [self::Running, self::Failed, self::Aborted], true),
            self::Failed, self::PartiallySucceeded => $to === self::Compensated,
            self::Succeeded, self::Compensated, self::Aborted, self::DeadLetter => false, // terminal
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
