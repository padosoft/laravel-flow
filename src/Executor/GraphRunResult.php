<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;

/**
 * Result of a whole {@see GraphRunner::run()}: the run id, the rolled-up run
 * state, each node's terminal state, each succeeded node's output port map, and
 * any per-node error messages.
 *
 * @api
 */
final readonly class GraphRunResult
{
    /**
     * @param  array<string, NodeState>  $nodeStates
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, string>  $errors  node id => error message
     */
    public function __construct(
        public string $runId,
        public RunState $state,
        public array $nodeStates,
        public array $nodeOutputs,
        public array $errors,
    ) {}
}
