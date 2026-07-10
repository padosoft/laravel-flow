<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Readonly execution context handed to every node handler.
 * `$inputs` is keyed by input port key and already validated.
 *
 * `$queued` is true when the node is running on the queued executor (a per-node
 * job) and false on the synchronous graph runner. Ordinary nodes ignore it;
 * fan-out/sub-flow control nodes use it to choose between suspending and spawning
 * child runs (queued) versus running children inline (sync).
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
        public readonly bool $queued = false,
    ) {}
}
