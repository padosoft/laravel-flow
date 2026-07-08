<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use InvalidArgumentException;
use JsonException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Node\NodeRegistry;

/**
 * Imports the ModelsGenerator "Flow v2" prototype graph shape into an
 * executable {@see GraphDefinition}. Accepts either the full envelope
 * `{version, kind: 'flow2', config: {nodes, connections}}` or a bare
 * `{nodes, connections}` config payload with no envelope wrapper.
 *
 * Field mapping: node `serviceType` -> {@see GraphNode::$type}, node
 * `data` -> {@see GraphNode::$config}, `position` passes through
 * unchanged. Connection `id` (the source app's own identity, unrelated to
 * {@see Connection::identity()}) is dropped; the remaining
 * `sourceNodeId`/`sourcePortKey`/`targetNodeId`/`targetPortKey` fields map
 * 1:1 onto {@see Connection}.
 *
 * Only STRUCTURAL validity is enforced here, via the {@see GraphDefinition}
 * constructor (node id/type presence, referential integrity, acyclicity).
 * The ModelsGenerator `serviceType` catalog is foreign to this package's
 * {@see NodeRegistry}, so semantic validation
 * ({@see GraphValidator}) cannot run against it here — that is an explicit
 * opt-in for the caller once matching node types are registered locally
 * (for example when the imported graph is later published through
 * {@see DefinitionRepository::publish()},
 * which always validates semantically).
 *
 * @api
 */
final class Flow2Importer
{
    private const KIND = 'flow2';

    /**
     * @throws InvalidGraphException when the JSON is malformed, the
     *                               envelope carries an unsupported `kind`, or any node/connection
     *                               entry is malformed or structurally invalid
     */
    public function import(string $json): GraphDefinition
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidGraphException(['Invalid JSON payload: '.$e->getMessage()]);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidGraphException(['Flow-v2 payload must decode to an object.']);
        }

        $config = $this->resolveConfig($decoded);

        $violations = [];
        $nodes = $this->importNodes($config, $violations);
        $connections = $this->importConnections($config, $violations);

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }

        // Remaining structural invariants (non-empty graph, duplicate ids,
        // referential integrity, acyclicity) are enforced by the VO itself.
        return new GraphDefinition($nodes, $connections);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function resolveConfig(array $decoded): array
    {
        if (! array_key_exists('config', $decoded)) {
            // No envelope wrapper: the whole payload IS the bare
            // {nodes, connections} config.
            return $decoded;
        }

        if (($decoded['kind'] ?? null) !== self::KIND) {
            throw new InvalidGraphException(["Unsupported or missing Flow-v2 envelope kind; expected '".self::KIND."'."]);
        }

        $config = $decoded['config'];

        if (! is_array($config)) {
            throw new InvalidGraphException(['Flow-v2 envelope [config] must be an array.']);
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $violations
     * @return list<GraphNode>
     */
    private function importNodes(array $config, array &$violations): array
    {
        $nodes = [];

        foreach (is_array($config['nodes'] ?? null) ? $config['nodes'] : [] as $index => $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_string($node['serviceType'] ?? null)) {
                $violations[] = "Malformed Flow-v2 node entry at index {$index}.";

                continue;
            }

            $data = $node['data'] ?? [];

            if (! is_array($data)) {
                $violations[] = "Flow-v2 node at index {$index}: [data] must be an array.";

                continue;
            }

            $position = array_key_exists('position', $node) ? $node['position'] : null;

            if ($position !== null && ! is_array($position)) {
                $violations[] = "Flow-v2 node at index {$index}: [position] must be an array or null.";

                continue;
            }

            try {
                // GraphNode only ever throws plain InvalidArgumentException.
                $nodes[] = new GraphNode($node['id'], $node['serviceType'], $data, $position);
            } catch (InvalidArgumentException $e) {
                $violations[] = "Flow-v2 node at index {$index}: {$e->getMessage()}";
            }
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $violations
     * @return list<Connection>
     */
    private function importConnections(array $config, array &$violations): array
    {
        $connections = [];

        foreach (is_array($config['connections'] ?? null) ? $config['connections'] : [] as $index => $wire) {
            if (! is_array($wire)
                || ! is_string($wire['sourceNodeId'] ?? null) || ! is_string($wire['sourcePortKey'] ?? null)
                || ! is_string($wire['targetNodeId'] ?? null) || ! is_string($wire['targetPortKey'] ?? null)) {
                $violations[] = "Malformed Flow-v2 connection entry at index {$index}.";

                continue;
            }

            try {
                // ModelsGenerator's own connection `id` (if present) is
                // dropped: Connection's identity is derived from its
                // endpoints, not a separate id field.
                $connections[] = new Connection($wire['sourceNodeId'], $wire['sourcePortKey'], $wire['targetNodeId'], $wire['targetPortKey']);
            } catch (InvalidArgumentException $e) {
                $violations[] = "Flow-v2 connection at index {$index}: {$e->getMessage()}";
            }
        }

        return $connections;
    }
}
