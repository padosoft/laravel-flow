<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Readonly execution context handed to every node handler.
 * `$inputs` is keyed by input port key and already validated.
 *
 * @api
 */
final class NodeContext
{
    /**
     * @param  array<string, mixed>  $inputs
     */
    public function __construct(
        public readonly string $flowRunId,
        public readonly string $definitionName,
        public readonly string $nodeId,
        public readonly array $inputs,
        public readonly bool $dryRun = false,
    ) {}
}
