<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Contract for every graph node handler. Implementations are resolved
 * through the Laravel container and MUST be annotated with #[FlowNode]
 * (the registry rejects them otherwise). Handlers MUST honour
 * `$context->dryRun`: when true, no persistent state may be mutated.
 *
 * @api
 */
interface FlowNodeHandler
{
    public function execute(NodeContext $context): NodeResult;
}
