<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * Outcome of a single {@see QueueGraphCoordinator::advance()} pass: the node ids
 * this pass exclusively claimed (and must therefore dispatch a job for) and
 * whether every node has reached a terminal state (so the run was finalized).
 *
 * @internal
 */
final readonly class CoordinatorDecision
{
    /**
     * @param  list<string>  $claimed  node ids this pass won the compare-and-set claim on
     */
    public function __construct(
        public array $claimed,
        public bool $allTerminal,
    ) {}
}
