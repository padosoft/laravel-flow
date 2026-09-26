<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Padosoft\LaravelFlow\FlowStepResult;
use Throwable;

/**
 * Readonly DTO summarising one node execution. Factory semantics mirror
 * {@see FlowStepResult} 1:1, so every v1 step outcome (success, failure,
 * dry-run skip, pause) has an exact node-result counterpart.
 *
 * @api
 */
final class NodeResult
{
    /**
     * @param  array<string, mixed>  $outputs  keyed by output port key
     * @param  array<string, mixed>|null  $businessImpact
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $outputs,
        public readonly ?Throwable $error,
        public readonly ?array $businessImpact,
        public readonly bool $dryRunSkipped,
        public readonly bool $paused,
        /**
         * Output ports a {@see self::branch()} result activated; null for every
         * non-branching result (all ports live — the historical behaviour).
         *
         * @var list<string>|null
         */
        public readonly ?array $activePorts = null,
        /**
         * When a {@see self::pausedUntil()} result: the point in time the node
         * resumes at. Null for every other result.
         */
        public readonly ?DateTimeImmutable $resumeAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function success(array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, false);
    }

    /**
     * A successful result that activates ONLY the listed output ports. Every
     * wire leaving an inactive port is dead: a downstream node whose incoming
     * wires are all dead is skipped instead of run (explicit, opt-in branching
     * — a node that simply omits an optional output keeps today's behaviour).
     * `$outputs` for inactive ports are dropped by the executor.
     *
     * A join after a branch must tolerate the dead wire (`flow.merge`, or
     * optional input ports): a node with at least one live incoming wire still
     * runs, with the dead wire's input absent.
     *
     * @param  array<string, mixed>  $outputs  keyed by output port key
     * @param  list<string>  $activePorts  the output port keys to activate
     * @param  array<string, mixed>|null  $businessImpact
     *
     * @throws InvalidArgumentException when the list is empty, holds a blank/non-string key,
     *                                  a duplicate, or a reserved `_`-prefixed key
     */
    public static function branch(array $outputs, array $activePorts, ?array $businessImpact = null): self
    {
        if ($activePorts === []) {
            throw new InvalidArgumentException('A branch result must activate at least one output port.');
        }

        $seen = [];

        foreach ($activePorts as $port) {
            if (! is_string($port) || trim($port) === '' || str_starts_with($port, '_')) {
                throw new InvalidArgumentException('A branch result may only activate non-blank output port keys that do not start with "_".');
            }

            if (isset($seen[$port])) {
                throw new InvalidArgumentException(sprintf('Output port [%s] is activated twice.', $port));
            }

            $seen[$port] = true;
        }

        return new self(true, $outputs, null, $businessImpact, false, false, array_values($activePorts));
    }

    public static function failed(Throwable $error): self
    {
        return new self(false, [], $error, null, false, false);
    }

    public static function dryRunSkipped(): self
    {
        return new self(true, [], null, null, true, false);
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function paused(array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, true);
    }

    /**
     * Pause the node until `$resumeAt`, then complete it as `succeeded` with
     * `$outputs`. Unlike {@see self::paused()} (which waits for an external
     * decision) the engine itself resumes the node at the due time — so a delay
     * or timer node needs no worker to sleep.
     *
     * On a queued run the pause is persisted (`flow_run_nodes.resume_at`) and a
     * delayed job resumes the node; `flow:resume-due-timers` is the safety net
     * for a lost job or a queue driver that cannot delay. On a synchronous run
     * the executor sleeps inline when the wait is within
     * `laravel-flow.executor.max_inline_delay_seconds` and fails the node with
     * an actionable message otherwise. A dry run never waits. A time already
     * in the past completes the node immediately.
     *
     * @param  array<string, mixed>  $outputs  keyed by output port key; delivered when the node resumes
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function pausedUntil(DateTimeInterface $resumeAt, array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, true, null, DateTimeImmutable::createFromInterface($resumeAt));
    }
}
