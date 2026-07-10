<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Attributes;

use Attribute;

/**
 * Marks a node whose output may be served from the content-hash cache: on a hit
 * for the same `(nodeType, resolvedInputs, nodeConfig)` the handler is skipped
 * and the cached outputs are returned. Opt-in per handler (like {@see Retry}).
 *
 * `$ttl` is the cache lifetime in seconds; null means the entry never expires.
 * Caching is inert on a dry run and is skipped entirely whenever persistence
 * redaction would alter the node's output (so a cache hit can never diverge
 * from what a miss would have produced) — do NOT mark a secret-producing node
 * `#[Cacheable]` and expect it to be cached.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Cacheable
{
    public function __construct(
        public readonly ?int $ttl = null,
    ) {}
}
