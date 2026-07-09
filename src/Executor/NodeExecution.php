<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;
use Throwable;

/**
 * Outcome of executing a single node through {@see NodeExecutor}: its terminal
 * state, the output port map (for downstream routing) and any error.
 *
 * @internal
 */
final readonly class NodeExecution
{
    /**
     * @param  array<string, mixed>  $outputs
     */
    public function __construct(
        public string $nodeId,
        public NodeState $state,
        public array $outputs,
        public ?Throwable $error = null,
    ) {}
}
