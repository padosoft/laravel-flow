<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use InvalidArgumentException;

/**
 * Immutable wire between an output port and an input port of two
 * distinct nodes in a {@see GraphDefinition}.
 *
 * @api
 */
final class Connection
{
    public function __construct(
        public readonly string $sourceNodeId,
        public readonly string $sourcePortKey,
        public readonly string $targetNodeId,
        public readonly string $targetPortKey,
    ) {
        foreach ([$this->sourceNodeId, $this->sourcePortKey, $this->targetNodeId, $this->targetPortKey] as $field) {
            if (trim($field) === '') {
                throw new InvalidArgumentException('Connection fields must not be empty.');
            }
        }

        if ($this->sourceNodeId === $this->targetNodeId) {
            throw new InvalidArgumentException("Connection on [{$this->sourceNodeId}] cannot wire a node to itself.");
        }
    }

    public function identity(): string
    {
        return "{$this->sourceNodeId}.{$this->sourcePortKey}>{$this->targetNodeId}.{$this->targetPortKey}";
    }
}
