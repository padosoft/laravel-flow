<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Illuminate\Contracts\Container\Container;
use Padosoft\LaravelFlow\FlowDefinition;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeRegistry;

/**
 * Resolves a {@see GraphNode} to its {@see NodeDefinition} and an executable
 * handler. Normal node types come from the registry and are built through the
 * container; a compiled v1 step (`legacy.step`) is resolved by wrapping the
 * container-built {@see FlowStepHandler} in a {@see LegacyStepNodeAdapter},
 * closing the Macro A legacy-resolution deferral. Unknown non-legacy types
 * propagate {@see UnknownNodeTypeException}.
 *
 * @api
 */
final class NodeResolver
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly Container $container,
    ) {}

    public function resolve(GraphNode $node): ResolvedNode
    {
        if ($node->type === FlowDefinition::LEGACY_NODE_TYPE) {
            return $this->resolveLegacy($node);
        }

        $definition = $this->registry->get($node->type);
        $handler = $this->container->make($definition->handlerClass);

        if (! $handler instanceof FlowNodeHandler) {
            throw new InvalidNodeDefinitionException("Handler [{$definition->handlerClass}] for node type [{$node->type}] must implement ".FlowNodeHandler::class.'.');
        }

        return new ResolvedNode($definition, $handler);
    }

    private function resolveLegacy(GraphNode $node): ResolvedNode
    {
        $handlerClass = $node->config['handler'] ?? null;

        if (! is_string($handlerClass) || $handlerClass === '') {
            throw new InvalidNodeDefinitionException("Legacy node [{$node->id}] is missing a string 'handler' config value.");
        }

        $definition = LegacyStepNodeAdapter::definitionFor($node->type, $handlerClass);
        $step = $this->container->make($handlerClass);

        if (! $step instanceof FlowStepHandler) {
            throw new InvalidNodeDefinitionException("Legacy handler [{$handlerClass}] for node [{$node->id}] must implement ".FlowStepHandler::class.'.');
        }

        return new ResolvedNode($definition, new LegacyStepNodeAdapter($step));
    }
}
