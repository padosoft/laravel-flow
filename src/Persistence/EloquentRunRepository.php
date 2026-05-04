<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use RuntimeException;

final class EloquentRunRepository implements ConditionalRunRepository, RunRepository
{
    /**
     * @var list<string>
     */
    private const UPDATABLE_COLUMNS = [
        'business_impact',
        'compensated',
        'compensation_status',
        'duration_ms',
        'failed_step',
        'finished_at',
        'output',
        'status',
    ];

    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function create(array $attributes): FlowRunRecord
    {
        $model = $this->newModel();
        $model->forceFill($this->redact($attributes))->save();

        return $model->refresh();
    }

    public function update(string $runId, array $attributes): FlowRunRecord
    {
        $model = $this->find($runId);

        if (! $model instanceof FlowRunRecord) {
            throw new RuntimeException(sprintf('Flow run [%s] was not found.', $runId));
        }

        $model->forceFill($this->redact($this->onlyUpdatable($attributes)))->save();

        return $model->refresh();
    }

    public function updateWhereStatus(string $runId, string $expectedStatus, array $attributes): ?FlowRunRecord
    {
        $attributes = $this->redact($this->onlyUpdatable($attributes));

        if ($attributes === []) {
            return $this->newModel()->newQuery()
                ->where('id', $runId)
                ->where('status', $expectedStatus)
                ->first();
        }

        $model = $this->newModel();
        $attributes['updated_at'] = $model->freshTimestamp();
        $model->forceFill($attributes);

        $updated = $model->newQuery()
            ->where('id', $runId)
            ->where('status', $expectedStatus)
            ->update($model->getAttributes());

        if ($updated !== 1) {
            return null;
        }

        return $this->find($runId);
    }

    public function find(string $runId): ?FlowRunRecord
    {
        return $this->newModel()->newQuery()->find($runId);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord
    {
        return $this->newModel()->newQuery()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function newModel(): FlowRunRecord
    {
        return (new FlowRunRecord)->setConnection($this->connection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function onlyUpdatable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::UPDATABLE_COLUMNS));
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
            PersistencePayloadRedaction::RUN_JSON_FIELDS,
        );
    }
}
