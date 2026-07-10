<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\State\NodeState;

/**
 * The outcome of the {@see JoinCoordinator} resuming a parent fan-out/sub-flow
 * node once its last child terminated: the parent run + node to advance, the
 * rolled-up parent node state, and the ordered per-child output list.
 *
 * @internal
 */
final readonly class JoinResult
{
    /**
     * @param  list<mixed>  $outputs  each child's output, ordered by child_index
     */
    public function __construct(
        public string $parentRunId,
        public string $parentNodeId,
        public NodeState $parentState,
        public array $outputs,
    ) {}
}
