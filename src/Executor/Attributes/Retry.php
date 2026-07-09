<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Attributes;

use Attribute;

/**
 * Declares a node's retry/backoff/timeout policy on the handler class or its
 * `execute()` method. Consumed by the graph executor via {@see RetryPolicy};
 * a graph node's `config['retry']` overrides these values per placement.
 *
 * `$backoff` is seconds: an int for a fixed delay, or a `list<int>` for a
 * per-attempt schedule (clamped to the last value once exhausted). `$timeout`
 * (seconds, 0 = none) is a post-hoc wall-clock check in the synchronous runner;
 * preemptive enforcement is a queue-worker concern.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Retry
{
    /**
     * @param  int|list<int>  $backoff
     */
    public function __construct(
        public readonly int $tries = 1,
        public readonly int|array $backoff = 0,
        public readonly int $timeout = 0,
    ) {}
}
