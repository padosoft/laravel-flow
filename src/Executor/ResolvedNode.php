<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeDefinition;

/**
 * A graph node resolved to its typed definition and an executable handler
 * instance (a real {@see FlowNodeHandler}, or a v1 step wrapped in
 * {@see LegacyStepNodeAdapter}).
 *
 * @api
 */
final readonly class ResolvedNode
{
    public function __construct(
        public NodeDefinition $definition,
        public FlowNodeHandler $handler,
    ) {}
}
