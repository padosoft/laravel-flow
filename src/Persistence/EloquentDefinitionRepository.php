<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Models\FlowDefinitionRecord;

/**
 * @internal
 */
final class EloquentDefinitionRepository implements DefinitionRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly GraphValidator $validator,
        private readonly GraphSerializer $serializer = new GraphSerializer,
    ) {}

    public function createDraft(string $name, GraphDefinition $graph): StoredDefinition
    {
        $payload = $this->serializer->toArray($graph);
        $checksum = $this->serializer->checksum($graph);

        return DB::connection($this->connection)->transaction(function () use ($name, $payload, $checksum): StoredDefinition {
            // Same name-group lock as publish(): serializes concurrent
            // drafts of one name so max(version)+1 cannot collide (the
            // unique index stays as the last-resort backstop; sqlite
            // no-ops the lock but serializes writers anyway).
            $this->newModel()->newQuery()->where('name', $name)->select('id')->lockForUpdate()->get();

            $nextVersion = ((int) $this->newModel()->newQuery()->where('name', $name)->max('version')) + 1;

            $model = $this->newModel();
            $model->forceFill([
                'checksum' => $checksum,
                'graph' => $payload,
                'name' => $name,
                'status' => StoredDefinition::STATUS_DRAFT,
                'version' => $nextVersion,
            ])->save();

            return $this->toStoredDefinition($model->refresh());
        });
    }

    public function find(string $name, int $version): StoredDefinition
    {
        return $this->toStoredDefinition($this->findModel($name, $version));
    }

    public function latest(string $name, ?string $status = null): ?StoredDefinition
    {
        $query = $this->newModel()->newQuery()->where('name', $name);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $model = $query->orderByDesc('version')->first();

        return $model instanceof FlowDefinitionRecord ? $this->toStoredDefinition($model) : null;
    }

    /**
     * Concurrency: publishing must serialize on the WHOLE `name` group, not
     * just the target row. If two draft versions of the same name were
     * published in overlapping transactions and each only locked its own
     * row, the "archive previously published" step could match zero rows
     * in both transactions (neither published row exists yet), leaving two
     * simultaneously published rows for one name. To prevent that, this
     * method takes `lockForUpdate()` over EVERY row of `$name` up front —
     * covering both the target draft and any currently published row — and
     * finds the target among those already-locked models instead of
     * issuing a second query. On InnoDB, a concurrent publish() for the
     * same name blocks on this SELECT ... FOR UPDATE until the first
     * transaction commits or rolls back, so the two-published-rows race is
     * closed. On SQLite, `lockForUpdate()` is a no-op; the existing
     * whole-connection write serialization already prevents the race in
     * tests, so it cannot be exercised here (see DefinitionRepositoryTest).
     *
     * @throws InvalidGraphException when the stored graph fails semantic validation
     */
    public function publish(string $name, int $version): StoredDefinition
    {
        return DB::connection($this->connection)->transaction(function () use ($name, $version): StoredDefinition {
            $group = $this->newModel()->newQuery()
                ->where('name', $name)
                ->lockForUpdate()
                ->get();

            $model = null;

            // foreach+instanceof instead of Collection::first(callback):
            // Larastan cannot infer the model type through this builder
            // chain (same gap as versions() below), so a callback closure
            // typed against FlowDefinitionRecord fails argument.type.
            foreach ($group as $record) {
                if ($record instanceof FlowDefinitionRecord && $record->version === $version) {
                    $model = $record;

                    break;
                }
            }

            if (! ($model instanceof FlowDefinitionRecord)) {
                throw new DefinitionNotFoundException($name, $version);
            }

            if ($model->status !== StoredDefinition::STATUS_DRAFT) {
                throw new DefinitionLifecycleException($name, $version, $model->status, 'publish');
            }

            // Drafts may be semantically incomplete (Studio saves
            // work-in-progress); a published definition must be executable,
            // so it is validated against the current node catalog here.
            $this->validator->validate($this->serializer->fromArray($model->graph));

            $now = $model->freshTimestamp();

            foreach ($group as $record) {
                if ($record instanceof FlowDefinitionRecord && $record->status === StoredDefinition::STATUS_PUBLISHED) {
                    $record->forceFill(['status' => StoredDefinition::STATUS_ARCHIVED, 'updated_at' => $now])->save();
                }
            }

            $model->forceFill([
                'published_at' => $now,
                'status' => StoredDefinition::STATUS_PUBLISHED,
            ])->save();

            return $this->toStoredDefinition($model->refresh());
        });
    }

    /**
     * Concurrency: unlike {@see self::publish()}, archiving only ever
     * writes to its OWN row and never depends on or mutates the state of
     * any other version of `$name`, so there is no group invariant to
     * protect — locking the single target row via {@see self::lockedFindModel()}
     * is sufficient. A concurrent publish() for the same name still
     * cannot race past this: publish() locks every row of `$name`
     * (including this one) up front, so on InnoDB the two transactions
     * serialize on whichever row they both touch.
     */
    public function archive(string $name, int $version): StoredDefinition
    {
        return DB::connection($this->connection)->transaction(function () use ($name, $version): StoredDefinition {
            $model = $this->lockedFindModel($name, $version);

            if ($model->status === StoredDefinition::STATUS_ARCHIVED) {
                throw new DefinitionLifecycleException($name, $version, $model->status, 'archive');
            }

            $model->forceFill(['status' => StoredDefinition::STATUS_ARCHIVED])->save();

            return $this->toStoredDefinition($model->refresh());
        });
    }

    public function versions(string $name): array
    {
        $records = $this->newModel()->newQuery()
            ->where('name', $name)
            ->orderBy('version')
            ->get();

        $versions = [];

        foreach ($records as $record) {
            if (! ($record instanceof FlowDefinitionRecord)) {
                continue;
            }

            $versions[] = $this->toStoredDefinition($record);
        }

        return $versions;
    }

    private function findModel(string $name, int $version): FlowDefinitionRecord
    {
        $model = $this->newModel()->newQuery()
            ->where('name', $name)
            ->where('version', $version)
            ->first();

        if (! ($model instanceof FlowDefinitionRecord)) {
            throw new DefinitionNotFoundException($name, $version);
        }

        return $model;
    }

    private function lockedFindModel(string $name, int $version): FlowDefinitionRecord
    {
        $model = $this->newModel()->newQuery()
            ->where('name', $name)
            ->where('version', $version)
            ->lockForUpdate()
            ->first();

        if (! ($model instanceof FlowDefinitionRecord)) {
            throw new DefinitionNotFoundException($name, $version);
        }

        return $model;
    }

    private function toStoredDefinition(FlowDefinitionRecord $model): StoredDefinition
    {
        return new StoredDefinition(
            id: $model->id,
            name: $model->name,
            version: $model->version,
            status: $model->status,
            graph: $model->graph,
            checksum: $model->checksum,
            signature: $model->signature,
            publishedAt: $model->published_at instanceof DateTimeImmutable ? $model->published_at : null,
        );
    }

    private function newModel(): FlowDefinitionRecord
    {
        return (new FlowDefinitionRecord)->setConnection($this->connection);
    }
}
