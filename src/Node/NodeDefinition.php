<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Executor\Attributes\Cacheable;
use Padosoft\LaravelFlow\Executor\Attributes\Cost;
use Padosoft\LaravelFlow\Executor\RetryPolicy;

/**
 * Immutable, catalog-ready description of one node type.
 *
 * NodeDefinitionFactory is the sanctioned construction path: this
 * constructor performs no invariant checks of its own.
 *
 * @api
 */
final class NodeDefinition
{
    /**
     * @param  list<PortDefinition>  $inputs
     * @param  list<PortDefinition>  $outputs
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $icon,
        public readonly ?string $description,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly string $handlerClass,
        public readonly ?RetryPolicy $retry = null,
        public readonly ?Cacheable $cacheable = null,
        public readonly ?Cost $cost = null,
    ) {}

    public function input(string $key): ?PortDefinition
    {
        return $this->findPort($this->inputs, $key);
    }

    public function output(string $key): ?PortDefinition
    {
        return $this->findPort($this->outputs, $key);
    }

    /**
     * Catalog projection. Deliberately excludes `handlerClass`
     * (server-side implementation detail).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'type' => $this->type,
            'name' => $this->name,
            'category' => $this->category,
            'icon' => $this->icon,
            'description' => $this->description,
            'inputs' => array_map(static fn (PortDefinition $port): array => $port->toArray(), $this->inputs),
            'outputs' => array_map(static fn (PortDefinition $port): array => $port->toArray(), $this->outputs),
        ];

        if ($this->retry !== null) {
            $array['retry'] = $this->retry->toArray();
        }

        if ($this->cacheable !== null) {
            $array['cacheable'] = ['ttl' => $this->cacheable->ttl];
        }

        if ($this->cost !== null) {
            $array['cost'] = ['estimate' => $this->cost->estimate];
        }

        return $array;
    }

    /**
     * @param  list<PortDefinition>  $ports
     */
    private function findPort(array $ports, string $key): ?PortDefinition
    {
        foreach ($ports as $port) {
            if ($port->key === $key) {
                return $port;
            }
        }

        return null;
    }
}
