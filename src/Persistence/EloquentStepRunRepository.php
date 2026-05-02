<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Models\FlowStepRecord;

final class EloquentStepRunRepository implements StepRunRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function createOrUpdate(string $runId, string $stepName, array $attributes): FlowStepRecord
    {
        unset($attributes['id'], $attributes['run_id'], $attributes['step_name']);

        $model = $this->newModel()->newQuery()->firstOrNew([
            'run_id' => $runId,
            'step_name' => $stepName,
        ]);

        $model->forceFill($this->redact($attributes))->save();

        return $model->refresh();
    }

    public function forRun(string $runId): Collection
    {
        return $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->orderBy('sequence')
            ->get();
    }

    private function newModel(): FlowStepRecord
    {
        return (new FlowStepRecord)->setConnection($this->connection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        return PersistencePayloadRedaction::redactFields(
            $this->redactor,
            $attributes,
            PersistencePayloadRedaction::STEP_JSON_FIELDS,
        );
    }
}
