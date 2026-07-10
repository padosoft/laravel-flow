<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Attributes;

use Attribute;
use InvalidArgumentException;

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
    ) {
        // A ttl of 0/negative is nonsensical (it would write a row that is
        // already expired and always misses); reject it at construction so a
        // bad attribute fails fast as an invalid node definition.
        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException('#[Cacheable] ttl must be null (never expires) or a positive number of seconds.');
        }
    }
}
