<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Exceptions\DuplicateNodeTypeException;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;

/**
 * Single source of truth for available node types: feeds executor
 * resolution, the JSON catalog (Studio palette) and, later, MCP tool
 * schema generation. Replaces hand-maintained whitelists.
 *
 * @api
 */
final class NodeRegistry
{
    /** @var array<string, NodeDefinition> */
    private array $definitions = [];

    public function __construct(private readonly NodeDefinitionFactory $factory) {}

    /**
     * @param  class-string  $handlerClass
     */
    public function register(string $handlerClass): NodeDefinition
    {
        if (! is_a($handlerClass, FlowNodeHandler::class, true)) {
            throw new InvalidNodeDefinitionException(
                "Node handler [{$handlerClass}] must implement ".FlowNodeHandler::class.'.'
            );
        }

        $definition = $this->factory->fromClass($handlerClass);

        if (isset($this->definitions[$definition->type])) {
            throw new DuplicateNodeTypeException($definition->type);
        }

        $this->definitions[$definition->type] = $definition;

        return $definition;
    }

    /**
     * @param  list<class-string>  $handlerClasses
     */
    public function registerMany(array $handlerClasses): void
    {
        foreach ($handlerClasses as $handlerClass) {
            $this->register($handlerClass);
        }
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): NodeDefinition
    {
        return $this->definitions[$type]
            ?? throw new UnknownNodeTypeException($type);
    }

    /**
     * @return array<string, NodeDefinition> keyed by type, sorted by type
     */
    public function all(): array
    {
        $all = $this->definitions;
        ksort($all);

        return $all;
    }
}
