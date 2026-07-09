<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\State;

/**
 * Per-node lifecycle state for the graph executor, and the single source of
 * truth for the persisted `flow_run_nodes.status` string of both engines: a
 * v1 linear step and a graph node share this vocabulary. Each case value is
 * the exact string v1 already persisted (`succeeded`, `paused`, `skipped`, …),
 * so unification extends the vocabulary without changing stored status strings.
 *
 * `isTerminal()` means this node has reached a stopping point for its
 * execution — v1 never retries a step (`'failed'` is v1's genuinely final
 * step status), so a node sitting at `Failed` with no retry policy configured
 * is exactly as done as one that `Succeeded`. Terminal-ness is therefore
 * ORTHOGONAL to {@see self::canTransitionTo()}'s optional `Failed` ->
 * `Running` retry edge: retrying is an OPTIONAL follow-up available from an
 * already-terminal state, not a requirement to leave it, mirroring
 * {@see RunState}'s `Failed`/`PartiallySucceeded` -> `Compensated` edge. The
 * edge is defined here now; the per-node `#[Retry]` policy that drives it
 * arrives in C-PR4.
 *
 * @api
 */
enum NodeState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Blocked = 'blocked';
    case InvalidInput = 'invalid_input';
    case DeadLetter = 'dead_letter';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Skipped, self::Blocked, self::InvalidInput, self::DeadLetter => true,
            self::Pending, self::Running, self::Paused => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => in_array($to, [self::Running, self::Skipped, self::Blocked, self::InvalidInput], true),
            self::Running => in_array($to, [self::Paused, self::Succeeded, self::Failed, self::DeadLetter], true),
            self::Paused => in_array($to, [self::Running, self::Failed], true), // resume / reject
            self::Failed => in_array($to, [self::Running, self::DeadLetter], true), // optional retry / give up
            self::Succeeded, self::Skipped, self::Blocked, self::InvalidInput, self::DeadLetter => false, // terminal, no further transitions at all
        };
    }

    public function transitionTo(self $to): self
    {
        if (! $this->canTransitionTo($to)) {
            throw IllegalStateTransitionException::for('NodeState', $this->value, $to->value);
        }

        return $to;
    }
}
