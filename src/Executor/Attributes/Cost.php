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
            // Reject surrounding whitespace, not just an all-whitespace string:
            // 'tokens' and 'tokens ' would otherwise be two DIFFERENT PHP array
            // keys that silently double-count the same dimension in the
            // planner's summed totals.
            if (! is_string($dimension) || $dimension === '' || trim($dimension) !== $dimension) {
                throw new InvalidArgumentException('#[Cost] estimate dimensions must be non-empty strings with no surrounding whitespace.');
            }

            if (! is_int($amount) && ! is_float($amount)) {
                throw new InvalidArgumentException("#[Cost] estimate [{$dimension}] must be an int or float.");
            }

            // A non-finite float (NAN/INF) would poison the planner's summed
            // totals and break JSON serialization when the definition is
            // projected via toArray() — reject it here, same as a negative
            // amount.
            if (is_float($amount) && ! is_finite($amount)) {
                throw new InvalidArgumentException("#[Cost] estimate [{$dimension}] must be a finite number.");
            }

            if ($amount < 0) {
                throw new InvalidArgumentException("#[Cost] estimate [{$dimension}] must not be negative.");
            }
        }
    }
}
