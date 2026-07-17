<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;

/**
 * @internal
 */
final class EloquentRunNodeRepository implements RunNodeRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord
    {
        unset($attributes['id'], $attributes['run_id'], $attributes['node_id']);

        $values = $this->databaseAttributesFor($runId, $nodeId, $attributes);

        $this->newModel()->newQuery()->upsert(
            [$values],
            ['run_id', 'node_id'],
            $this->updatableColumns($values),
        );

        /** @var FlowRunNodeRecord $record */
        $record = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->firstOrFail();

        return $record;
    }

    public function forRun(string $runId): Collection
    {
        // A bare `orderBy('sequence')` relies on the DB driver's default NULL
        // ordering, which is NOT portable: MySQL/SQLite sort NULL first in
        // ASC, PostgreSQL sorts NULL LAST by default. The explicit CASE WHEN
        // guarantees the documented "null sorts before sequenced rows"
        // contract identically across every driver Laravel supports.
        return $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->orderByRaw('CASE WHEN sequence IS NULL THEN 0 ELSE 1 END')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    public function states(string $runId): array
    {
        /** @var array<string, NodeState> $states */
        $states = [];

        foreach ($this->newModel()->newQuery()->where('run_id', $runId)->get(['node_id', 'status']) as $row) {
            $states[(string) $row->node_id] = NodeState::from((string) $row->status);
        }

        return $states;
    }

    public function claim(string $runId, string $nodeId, DateTimeInterface $startedAt): bool
    {
        $affected = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('status', NodeState::Pending->value)
            ->update([
                'status' => NodeState::Running->value,
                'started_at' => $startedAt,
                'updated_at' => $this->newModel()->freshTimestamp(),
            ]);

        return $affected === 1;
    }

    public function releaseClaim(string $runId, string $nodeId): bool
    {
        $affected = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('status', NodeState::Running->value)
            ->update([
                'status' => NodeState::Pending->value,
                'started_at' => null,
                'updated_at' => $this->newModel()->freshTimestamp(),
            ]);

        return $affected === 1;
    }

    public function terminate(string $runId, string $nodeId, string $expectedStatus, string $newStatus, DateTimeInterface $finishedAt, ?int $durationMs, ?string $errorClass = null, ?string $errorMessage = null): bool
    {
        $affected = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('status', $expectedStatus)
            ->update([
                'status' => $newStatus,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'error_class' => $errorClass,
                'error_message' => $errorMessage,
                'updated_at' => $this->newModel()->freshTimestamp(),
            ]);

        return $affected === 1;
    }

    private function newModel(): FlowRunNodeRecord
    {
        return (new FlowRunNodeRecord)->setConnection($this->connection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function databaseAttributesFor(string $runId, string $nodeId, array $attributes): array
    {
        $model = $this->newModel();
        $timestamp = $model->freshTimestamp();
        $attributes = $this->redact($attributes);
        $attributes['created_at'] ??= $timestamp;
        $attributes['updated_at'] = $timestamp;

        $model->forceFill([
            'run_id' => $runId,
            'node_id' => $nodeId,
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
            ['id', 'run_id', 'node_id', 'created_at'],
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
            PersistencePayloadRedaction::NODE_JSON_FIELDS,
        );
    }
}
