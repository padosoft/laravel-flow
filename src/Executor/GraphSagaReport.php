<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * Outcome of one {@see GraphSaga::compensate()} pass: which nodes rolled back,
 * which compensators failed (keyed by node id, or {@see GraphSaga::AGGREGATE_KEY}
 * for the graph-level aggregate compensator), and whether the aggregate ran.
 *
 * A run is marked `compensated` ONLY when {@see fullySucceeded()} — every
 * intended compensator (per-node and aggregate) completed without error. A
 * partial rollback keeps the run's failure state and records
 * `compensation_status = 'failed'` so the operator sees the gap.
 *
 * @api
 */
final readonly class GraphSagaReport
{
    /**
     * @param  list<string>  $compensatedNodeIds  in deterministic reverse-topological candidate order — under the sequential strategy this IS the execution order; under `parallel` the tasks complete in nondeterministic order and this list reflects the batching order, not completion order
     * @param  array<string, string>  $errors  node id (or aggregate key) => error message
     */
    public function __construct(
        public array $compensatedNodeIds,
        public array $errors,
        public bool $aggregateCompensated,
    ) {}

    /**
     * True when the saga had anything to do: at least one per-node compensator
     * ran (or failed), or the aggregate compensator ran (or failed).
     */
    public function attempted(): bool
    {
        return $this->compensatedNodeIds !== [] || $this->errors !== [] || $this->aggregateCompensated;
    }

    /**
     * True when compensation was attempted and EVERY intended compensator
     * succeeded — the only outcome that marks the run `compensated`.
     */
    public function fullySucceeded(): bool
    {
        return $this->attempted() && $this->errors === [];
    }
}
