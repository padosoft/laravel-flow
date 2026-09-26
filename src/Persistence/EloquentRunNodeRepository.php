<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Padosoft\LaravelFlow\Contracts\BranchAwareRunNodeRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Contracts\TimerRepository;
use Padosoft\LaravelFlow\Exceptions\PersistenceUnavailableException;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;

/**
 * @internal
 */
final class EloquentRunNodeRepository implements BranchAwareRunNodeRepository, RunNodeRepository, TimerRepository
{
    private ?bool $hasActivePortsColumn = null;

    private ?bool $hasResumeAtColumn = null;

    /** A completed timer whose run made no progress for this long is re-driven by the sweeper. */
    private const STALLED_GRACE_SECONDS = 60;

    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function createOrUpdate(string $runId, string $nodeId, array $attributes): FlowRunNodeRecord
    {
        unset($attributes['id'], $attributes['run_id'], $attributes['node_id']);

        // Only a branching graph writes `active_ports`; fail with an actionable
        // message (not a raw "unknown column" QueryException) when the v2.6
        // migration has not been run.
        if (array_key_exists('active_ports', $attributes) && ! $this->activePortsColumnExists()) {
            throw new PersistenceUnavailableException('flow_run_nodes.active_ports is missing: publish and run the laravel-flow v2.6 migrations to use branching nodes.');
        }

        if (array_key_exists('resume_at', $attributes) && ! $this->resumeAtColumnExists()) {
            throw new PersistenceUnavailableException('flow_run_nodes.resume_at is missing: publish and run the laravel-flow v2.6 migrations to use timer nodes.');
        }

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

    public function activePorts(string $runId): array
    {
        // No column means no branch decision was ever persisted, so "no node
        // branched" is the correct answer on an unmigrated database.
        if (! $this->activePortsColumnExists()) {
            return [];
        }

        /** @var array<string, list<string>> $ports */
        $ports = [];

        foreach ($this->newModel()->newQuery()->where('run_id', $runId)->whereNotNull('active_ports')->get(['node_id', 'active_ports']) as $row) {
            $list = $row->active_ports;

            if (is_array($list)) {
                $ports[(string) $row->node_id] = array_values(array_map('strval', $list));
            }
        }

        return $ports;
    }

    public function dueTimers(DateTimeInterface $now, int $limit): array
    {
        if (! $this->resumeAtColumnExists()) {
            return [];
        }

        $limit = max(1, $limit);
        $timers = [];

        foreach ($this->newModel()->newQuery()
            ->where('status', NodeState::Paused->value)
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', $now)
            ->orderBy('resume_at')
            ->limit($limit)
            ->get(['run_id', 'node_id']) as $row) {
            $timers[] = ['run_id' => (string) $row->run_id, 'node_id' => (string) $row->node_id];
        }

        // Also a timer that was COMPLETED but whose run never advanced: the flip
        // committed and then the coordinator could not be enqueued (a queue
        // outage), and no job retry is coming (e.g. `--sync`, or retries
        // exhausted). Such a run is still `running` yet has no node in flight
        // (`running`) — whether nodes are waiting (`pending`) or the timer was the
        // last work and only the finalize is missing (a leaf timer). The grace
        // period keeps a healthy run that is simply between two coordinator passes
        // from being re-driven. Re-driving is safe either way: the coordinator's
        // claims are compare-and-set and a finished run is finalized once.
        $remaining = $limit - count($timers);

        if ($remaining > 0) {
            $connection = $this->newModel()->getConnection();
            $grace = DateTimeImmutable::createFromInterface($now)->modify('-'.self::STALLED_GRACE_SECONDS.' seconds');

            foreach ($connection->table('flow_run_nodes as timer')
                ->where('timer.status', NodeState::Succeeded->value)
                ->whereNotNull('timer.resume_at')
                ->where('timer.finished_at', '<=', $grace)
                ->whereExists(static fn ($query) => $query->from('flow_runs as run')
                    ->whereColumn('run.id', 'timer.run_id')
                    ->where('run.status', 'running'))
                ->whereNotExists(static fn ($query) => $query->from('flow_run_nodes as active')
                    ->whereColumn('active.run_id', 'timer.run_id')
                    ->where('active.status', NodeState::Running->value))
                ->orderBy('timer.finished_at')
                ->limit($remaining)
                ->get(['timer.run_id', 'timer.node_id']) as $row) {
                $timers[] = ['run_id' => (string) $row->run_id, 'node_id' => (string) $row->node_id];
            }
        }

        return $timers;
    }

    public function pendingTimer(string $runId, string $nodeId): ?DateTimeImmutable
    {
        if (! $this->resumeAtColumnExists()) {
            return null;
        }

        $row = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('status', NodeState::Paused->value)
            ->whereNotNull('resume_at')
            ->first(['resume_at']);

        $resumeAt = $row?->resume_at;

        return $resumeAt === null ? null : DateTimeImmutable::createFromInterface($resumeAt);
    }

    public function resumeTimer(string $runId, string $nodeId, DateTimeInterface $now): bool
    {
        if (! $this->resumeAtColumnExists()) {
            return false;
        }

        // The duration was persisted at the initial pause; the node only completes
        // now, so recompute it from its (unchanging) start time — otherwise the
        // row would report a finish time after the wait with the pre-wait duration.
        $startedAt = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->value('started_at');

        $values = [
            'status' => NodeState::Succeeded->value,
            'finished_at' => $now,
            'error_class' => null,
            'error_message' => null,
            'updated_at' => $this->newModel()->freshTimestamp(),
        ];

        if ($startedAt !== null) {
            $start = DateTimeImmutable::createFromInterface(is_string($startedAt) ? new DateTimeImmutable($startedAt) : $startedAt);
            $values['duration_ms'] = max(0, (int) round(((float) $now->format('U.u') - (float) $start->format('U.u')) * 1000));
        }

        $affected = $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('status', NodeState::Paused->value)
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', $now)
            ->update($values);

        return $affected === 1;
    }

    public function isResumedTimer(string $runId, string $nodeId): bool
    {
        if (! $this->resumeAtColumnExists()) {
            return false;
        }

        return $this->newModel()->newQuery()
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->where('status', NodeState::Succeeded->value)
            ->whereNotNull('resume_at')
            ->exists();
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

    private function activePortsColumnExists(): bool
    {
        return $this->hasActivePortsColumn ??= Schema::connection($this->connection)->hasColumn('flow_run_nodes', 'active_ports');
    }

    private function resumeAtColumnExists(): bool
    {
        return $this->hasResumeAtColumn ??= Schema::connection($this->connection)->hasColumn('flow_run_nodes', 'resume_at');
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
