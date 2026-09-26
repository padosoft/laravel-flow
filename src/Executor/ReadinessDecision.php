<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * Result of a single {@see ReadinessResolver::resolve()} pass over a graph:
 * the nodes that are ready to run now, the nodes newly poisoned by a failed
 * upstream, the nodes whose every incoming wire is dead (a branch that was not
 * taken), and whether every node has reached a terminal state.
 *
 * @api
 */
final readonly class ReadinessDecision
{
    /**
     * @param  list<string>  $ready  node ids ready to execute (topological order)
     * @param  list<string>  $blocked  node ids poisoned by an upstream failure
     * @param  list<string>  $skipped  node ids whose every incoming wire is dead (branch not taken)
     */
    public function __construct(
        public array $ready,
        public array $blocked,
        public bool $allTerminal,
        public array $skipped = [],
    ) {}
}
