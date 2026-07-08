<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use InvalidArgumentException;
use JsonException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;

/**
 * Canonical JSON envelope for graph definitions (schema v1) and the
 * stable content checksum used by the definition store, signing and the
 * node-level cache. The checksum canonicalizes by sorting the `nodes`
 * list by node id and the `connections` list by identity
 * (`sourceNodeId.sourcePortKey>targetNodeId.targetPortKey`), then
 * recursively sorting keys, so semantically identical payloads always
 * hash the same regardless of list order.
 *
 * @api
 */
final class GraphSerializer
{
    public const SCHEMA_VERSION = 1;

    public const KIND = 'laravel-flow';

    /**
     * @return array<string, mixed>
     */
    public function toArray(GraphDefinition $graph): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => self::KIND,
            'metadata' => $graph->metadata,
            'nodes' => array_map(static fn (GraphNode $node): array => [
                'id' => $node->id,
                'type' => $node->type,
                'config' => $node->config,
                'position' => $node->position,
            ], $graph->nodes),
            'connections' => array_map(static fn (Connection $wire): array => [
                'sourceNodeId' => $wire->sourceNodeId,
                'sourcePortKey' => $wire->sourcePortKey,
                'targetNodeId' => $wire->targetNodeId,
                'targetPortKey' => $wire->targetPortKey,
            ], $graph->connections),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromArray(array $payload): GraphDefinition
    {
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidGraphException(['Unsupported or missing schema_version; expected '.self::SCHEMA_VERSION.'.']);
        }

        if (($payload['kind'] ?? null) !== self::KIND) {
            throw new InvalidGraphException(["Unsupported or missing kind; expected '".self::KIND."'."]);
        }

        $violations = [];

        foreach (['nodes', 'connections', 'metadata'] as $field) {
            if (array_key_exists($field, $payload) && ! is_array($payload[$field])) {
                $violations[] = "Envelope field [{$field}] must be an array.";
            }
        }

        $nodes = [];

        foreach (is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [] as $index => $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_string($node['type'] ?? null)) {
                $violations[] = "Malformed node entry at index {$index}.";

                continue;
            }

            $config = array_key_exists('config', $node) ? $node['config'] : [];

            if (! is_array($config)) {
                $violations[] = "Node at index {$index}: [config] must be an array.";

                continue;
            }

            $position = array_key_exists('position', $node) ? $node['position'] : null;

            if ($position !== null && ! is_array($position)) {
                $violations[] = "Node at index {$index}: [position] must be an array or null.";

                continue;
            }

            try {
                // GraphNode/Connection only ever throw plain InvalidArgumentException, never InvalidGraphException.
                $nodes[] = new GraphNode($node['id'], $node['type'], $config, $position);
            } catch (InvalidArgumentException $e) {
                $violations[] = "Node at index {$index}: {$e->getMessage()}";
            }
        }

        $connections = [];

        foreach (is_array($payload['connections'] ?? null) ? $payload['connections'] : [] as $index => $wire) {
            if (! is_array($wire)
                || ! is_string($wire['sourceNodeId'] ?? null) || ! is_string($wire['sourcePortKey'] ?? null)
                || ! is_string($wire['targetNodeId'] ?? null) || ! is_string($wire['targetPortKey'] ?? null)) {
                $violations[] = "Malformed connection entry at index {$index}.";

                continue;
            }

            try {
                // GraphNode/Connection only ever throw plain InvalidArgumentException, never InvalidGraphException.
                $connections[] = new Connection($wire['sourceNodeId'], $wire['sourcePortKey'], $wire['targetNodeId'], $wire['targetPortKey']);
            } catch (InvalidArgumentException $e) {
                $violations[] = "Connection at index {$index}: {$e->getMessage()}";
            }
        }

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return new GraphDefinition($nodes, $connections, $metadata);
    }

    /**
     * @throws JsonException
     */
    public function toJson(GraphDefinition $graph, int $flags = 0): string
    {
        return json_encode($this->toArray($graph), $flags | JSON_THROW_ON_ERROR);
    }

    public function fromJson(string $json): GraphDefinition
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidGraphException(['Invalid JSON payload: '.$e->getMessage()]);
        }

        if (! is_array($decoded)) {
            throw new InvalidGraphException(['Graph payload must decode to an object.']);
        }

        return $this->fromArray($decoded);
    }

    /**
     * @throws JsonException
     */
    public function checksum(GraphDefinition $graph): string
    {
        $canonical = $this->toArray($graph);
        $this->sortListsByIdentity($canonical);
        $this->ksortRecursive($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * Sorts the `nodes` and `connections` lists so list order never
     * affects the checksum, only the graph's actual content does.
     *
     * @param  array<string, mixed>  $canonical
     */
    private function sortListsByIdentity(array &$canonical): void
    {
        if (isset($canonical['nodes']) && is_array($canonical['nodes'])) {
            usort($canonical['nodes'], static fn (array $a, array $b): int => ($a['id'] ?? '') <=> ($b['id'] ?? ''));
        }

        if (isset($canonical['connections']) && is_array($canonical['connections'])) {
            $identity = static fn (array $wire): string => ($wire['sourceNodeId'] ?? '').'.'.($wire['sourcePortKey'] ?? '').'>'.($wire['targetNodeId'] ?? '').'.'.($wire['targetPortKey'] ?? '');

            usort($canonical['connections'], static fn (array $a, array $b): int => $identity($a) <=> $identity($b));
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
        unset($item);
    }
}
