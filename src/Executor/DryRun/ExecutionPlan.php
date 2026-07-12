<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\DryRun;

/**
 * The execution plan a DAG dry-run produces: `$waves` are Kahn layers — wave 0
 * holds the root nodes, wave N the nodes whose predecessors all appear in
 * earlier waves — i.e. the sets of nodes the executor COULD run concurrently.
 *
 * `$skipped` lists nodes the planner already knows will not run. The current
 * planner is OPTIMISTIC: whether a node self-skips is only knowable at run
 * time (a handler decides its own dry-run behavior), so every node is planned
 * into a wave and `$skipped` stays empty — the list exists so a future planner
 * that CAN prove a branch dead has a stable place to put it.
 *
 * @api
 */
final readonly class ExecutionPlan
{
    /**
     * @param  list<list<string>>  $waves  node ids per wave, in execution order
     * @param  list<string>  $skipped  node ids known not to run (see class doc)
     */
    public function __construct(
        public array $waves,
        public array $skipped,
    ) {}

    /**
     * @return array{waves: list<list<string>>, skipped: list<string>}
     */
    public function toArray(): array
    {
        return ['waves' => $this->waves, 'skipped' => $this->skipped];
    }
}
