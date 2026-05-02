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

        $values = $this->databaseAttributesFor($runId, $stepName, $attributes);

        $this->newModel()->newQuery()->upsert(
            [$values],
            ['run_id', 'step_name'],
            $this->updatableColumns($values),
        );

        /** @var FlowStepRecord $record */
        $record = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('step_name', $stepName)
            ->firstOrFail();

        return $record;
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
    private function databaseAttributesFor(string $runId, string $stepName, array $attributes): array
    {
        $model = $this->newModel();
        $timestamp = $model->freshTimestamp();
        $attributes = $this->redact($attributes);
        $attributes['created_at'] ??= $timestamp;
        $attributes['updated_at'] = $timestamp;

        $model->forceFill([
            'run_id' => $runId,
            'step_name' => $stepName,
            ...$attributes,
        ]);

        $values = $model->getAttributes();
        unset($values['id']);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function updatableColumns(array $values): array
    {
        return array_values(array_diff(
            array_keys($values),
            ['id', 'run_id', 'step_name', 'created_at'],
        ));
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
