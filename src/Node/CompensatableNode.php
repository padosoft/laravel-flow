<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\FlowCompensator;

/**
 * Optional capability for a {@see FlowNodeHandler}: a node that knows how to
 * undo its own side effects when a graph run fails after it succeeded (saga
 * compensation). Implement it ON the handler class — the saga detects the
 * capability via `instanceof` on the resolved handler.
 *
 * The {@see NodeContext} handed to `compensate()` carries the node's recorded
 * OUTPUT port map in `$context->inputs` (what the node produced and must now
 * undo), not its original inputs — mirroring v1, where a
 * {@see FlowCompensator} receives the step's RESULT.
 *
 * Compensation is best-effort by contract: a throwing compensator is recorded
 * and the saga keeps rolling back the remaining nodes.
 *
 * @api
 */
interface CompensatableNode
{
    public function compensate(NodeContext $context): void;
}
