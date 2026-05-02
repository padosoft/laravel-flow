<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use RuntimeException;

final class EloquentRunRepository implements RunRepository
{
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

        unset($attributes['id']);

        $model->forceFill($this->redact($attributes))->save();

        return $model->refresh();
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
    private function redact(array $attributes): array
    {
        foreach (['business_impact', 'input', 'output'] as $key) {
            if (isset($attributes[$key]) && is_array($attributes[$key])) {
                /** @var array<string, mixed> $payload */
                $payload = $attributes[$key];
                $attributes[$key] = $this->redactor->redact($payload);
            }
        }

        return $attributes;
    }
}
