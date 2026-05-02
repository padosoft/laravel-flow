<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Models\FlowStepRecord;

interface StepRunRepository
{
    /**
     * Persist a step record using the run id and step name as immutable identity.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrUpdate(string $runId, string $stepName, array $attributes): FlowStepRecord;

    /**
     * @return Collection<int, FlowStepRecord>
     */
    public function forRun(string $runId): Collection;
}
