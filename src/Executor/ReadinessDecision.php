<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * Result of a single {@see ReadinessResolver::resolve()} pass over a graph:
 * the nodes that are ready to run now, the nodes newly poisoned by a failed
 * upstream, and whether every node has reached a terminal state.
 *
 * @api
 */
final readonly class ReadinessDecision
{
    /**
     * @param  list<string>  $ready  node ids ready to execute (topological order)
     * @param  list<string>  $blocked  node ids poisoned by an upstream failure
     */
    public function __construct(
        public array $ready,
        public array $blocked,
        public bool $allTerminal,
    ) {}
}
