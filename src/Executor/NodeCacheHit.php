<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * A cache hit: the outputs (and optional business impact) a `#[Cacheable]` node
 * serves instead of running its handler.
 *
 * @api
 */
final class NodeCacheHit
{
    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public function __construct(
        public readonly array $outputs,
        public readonly ?array $businessImpact,
    ) {}
}
