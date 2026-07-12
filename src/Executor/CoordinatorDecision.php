<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * Outcome of a single {@see QueueGraphCoordinator::advance()} pass: the node ids
 * this pass exclusively claimed (and must therefore dispatch a job for) and
 * whether EVERY node reached a terminal state. Note `allTerminal` is strictly
 * about node states — it is NOT "the run was finalized": `advance()` also
 * finalizes a run that has merely settled with no work in flight (e.g. a paused
 * node), which is not an all-terminal state.
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
