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
    private const PENDING = 'pending';

    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function recordPending(string $runId, string $parentNodeId, int $childIndex, string $childFlow, ?int $childVersion, array $input): FlowNodeChildRecord
    {
        $model = $this->newModel();
        $timestamp = $model->freshTimestamp();

        $model->forceFill([
            'run_id' => $runId,
            'parent_node_id' => $parentNodeId,
            'child_index' => $childIndex,
            'child_flow' => $childFlow,
            'child_version' => $childVersion,
            'input' => $this->redact(['input' => $input], ['input'])['input'],
            'status' => self::PENDING,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $model->save();

        return $model;
    }

    public function activate(string $runId, string $parentNodeId, int $childIndex, string $childRunId, DateTimeInterface $startedAt): bool
    {
        $affected = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('parent_node_id', $parentNodeId)
            ->where('child_index', $childIndex)
            ->where('status', self::PENDING)
            ->update([
                'child_run_id' => $childRunId,
                'status' => NodeState::Running->value,
                'started_at' => $startedAt,
                'updated_at' => $this->newModel()->freshTimestamp(),
            ]);

        return $affected === 1;
    }

    public function nextPending(string $runId, string $parentNodeId): ?FlowNodeChildRecord
    {
        // forParent() is ordered by child_index, so the first pending row is the
        // lowest-index one to release next.
        return $this->forParent($runId, $parentNodeId)->firstWhere('status', self::PENDING);
    }

    public function countUnfinished(string $runId, string $parentNodeId): int
    {
        return $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('parent_node_id', $parentNodeId)
            ->whereIn('status', [self::PENDING, NodeState::Running->value])
            ->count();
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
            $values['outputs'] = $this->redact(['outputs' => $outputs], ['outputs'])['outputs'];
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

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function redact(array $attributes, array $fields): array
    {
        return PersistencePayloadRedaction::redactFields($this->redactor, $attributes, $fields);
    }

    private function newModel(): FlowNodeChildRecord
    {
        return (new FlowNodeChildRecord)->setConnection($this->connection);
    }
}
