<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares a node's estimated execution cost for the DAG dry-run planner —
 * arbitrary named dimensions with numeric values, e.g.
 * `#[Cost(estimate: ['tokens' => 1200, 'cents' => 3])]`. Opt-in per handler
 * (like {@see Retry} / {@see Cacheable}); the planner sums each dimension
 * across the nodes that would run. Purely advisory: the executor never reads
 * it at run time.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Cost
{
    /**
     * @param  array<string, int|float>  $estimate  dimension => estimated amount
     */
    public function __construct(
        public readonly array $estimate,
    ) {
        if ($estimate === []) {
            throw new InvalidArgumentException('#[Cost] estimate must declare at least one dimension.');
        }

        foreach ($estimate as $dimension => $amount) {
            if (! is_string($dimension) || trim($dimension) === '') {
                throw new InvalidArgumentException('#[Cost] estimate dimensions must be non-empty strings.');
            }

            if (! is_int($amount) && ! is_float($amount)) {
                throw new InvalidArgumentException("#[Cost] estimate [{$dimension}] must be an int or float.");
            }

            if ($amount < 0) {
                throw new InvalidArgumentException("#[Cost] estimate [{$dimension}] must not be negative.");
            }
        }
    }
}
