<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use InvalidArgumentException;

/**
 * Immutable node instance inside a {@see GraphDefinition}: which node
 * type runs, its per-instance config, and (for Studio) its canvas
 * position. `$position` is presentation metadata only — the engine
 * never reads it.
 *
 * @api
 */
final class GraphNode
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array{x: int|float, y: int|float}|null  $position
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $config = [],
        public readonly ?array $position = null,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Graph node id must not be empty.');
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException('Graph node type must not be empty.');
        }

        $isCoordinate = static fn (mixed $v): bool => is_int($v) || is_float($v);

        if ($this->position !== null && (! $isCoordinate($this->position['x'] ?? null) || ! $isCoordinate($this->position['y'] ?? null))) {
            throw new InvalidArgumentException("Graph node [{$this->id}] position must carry int|float x and y.");
        }
    }
}
