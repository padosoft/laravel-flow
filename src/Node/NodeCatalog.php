<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use JsonException;

/**
 * Serializes the registry into the versioned catalog consumed by the
 * Studio palette and external tooling.
 *
 * @api
 */
final class NodeCatalog
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @return array{schema_version: int, nodes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'nodes' => array_values(array_map(
                static fn (NodeDefinition $definition): array => $definition->toArray(),
                $this->registry->all(),
            )),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }
}
