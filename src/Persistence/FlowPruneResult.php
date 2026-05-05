<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

/**
 * @internal
 */
final class FlowPruneResult
{
    public function __construct(
        public readonly int $runs,
        public readonly int $steps,
        public readonly int $audit,
    ) {}

    public function total(): int
    {
        return $this->runs + $this->steps + $this->audit;
    }
}
