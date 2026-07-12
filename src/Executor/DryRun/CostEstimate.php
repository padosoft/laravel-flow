<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\DryRun;

/**
 * Cost projection for a planned DAG run, summed from the `#[Cost]` hints each
 * node's handler declares. Dimensions are free-form (e.g. `tokens`, `cents`):
 * `$perNode` maps node id => its declared estimate, `$total` sums every
 * dimension across the planned nodes. Nodes without a hint contribute nothing
 * and do not appear in `$perNode` — an empty estimate therefore means "no node
 * declared a cost", not "the run is free".
 *
 * @api
 */
final readonly class CostEstimate
{
    /**
     * @param  array<string, array<string, int|float>>  $perNode  node id => dimension => amount
     * @param  array<string, int|float>  $total  dimension => summed amount
     */
    public function __construct(
        public array $perNode,
        public array $total,
    ) {}

    /**
     * @return array{perNode: array<string, array<string, int|float>>, total: array<string, int|float>}
     */
    public function toArray(): array
    {
        return ['perNode' => $this->perNode, 'total' => $this->total];
    }
}
