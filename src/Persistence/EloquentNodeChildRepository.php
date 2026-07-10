<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\LaravelFlow\Contracts\NodeChildRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Models\FlowNodeChildRecord;

/**
 * @internal
 */
final class EloquentNodeChildRepository implements NodeChildRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function record(string $runId, string $parentNodeId, string $childRunId, int $childIndex, DateTimeInterface $startedAt): FlowNodeChildRecord
    {
        $model = $this->newModel();
        $timestamp = $model->freshTimestamp();

        $model->forceFill([
            'run_id' => $runId,
            'parent_node_id' => $parentNodeId,
            'child_run_id' => $childRunId,
            'child_index' => $childIndex,
            'status' => NodeState::Running->value,
            'started_at' => $startedAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $model->save();

        return $model;
    }

    public function findByChildRun(string $childRunId): ?FlowNodeChildRecord
    {
        return $this->newModel()->newQuery()->where('child_run_id', $childRunId)->first();
    }

    public function completeChild(string $childRunId, string $status, ?array $outputs, DateTimeInterface $finishedAt): bool
    {
        $values = [
            'status' => $status,
            'finished_at' => $finishedAt,
            'updated_at' => $this->newModel()->freshTimestamp(),
        ];

        if ($outputs !== null) {
            $redacted = PersistencePayloadRedaction::redactFields($this->redactor, ['outputs' => $outputs], ['outputs']);
            $values['outputs'] = $redacted['outputs'];
        }

        $affected = $this->newModel()->newQuery()
            ->where('child_run_id', $childRunId)
            ->where('status', NodeState::Running->value)
            ->update($values);

        return $affected === 1;
    }

    public function forParent(string $runId, string $parentNodeId): Collection
    {
        return $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('parent_node_id', $parentNodeId)
            ->orderBy('child_index')
            ->get();
    }

    private function newModel(): FlowNodeChildRecord
    {
        return (new FlowNodeChildRecord)->setConnection($this->connection);
    }
}
