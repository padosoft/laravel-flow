<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use Padosoft\LaravelFlow\Models\FlowDefinitionRecord;

/**
 * @internal
 */
final class EloquentDefinitionRepository implements DefinitionRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly GraphSerializer $serializer = new GraphSerializer,
    ) {}

    public function createDraft(string $name, GraphDefinition $graph): StoredDefinition
    {
        $payload = $this->serializer->toArray($graph);
        $checksum = $this->serializer->checksum($graph);

        return DB::connection($this->connection)->transaction(function () use ($name, $payload, $checksum): StoredDefinition {
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

    public function publish(string $name, int $version): StoredDefinition
    {
        return DB::connection($this->connection)->transaction(function () use ($name, $version): StoredDefinition {
            $model = $this->lockedFindModel($name, $version);

            if ($model->status !== StoredDefinition::STATUS_DRAFT) {
                throw new DefinitionLifecycleException($name, $version, $model->status, 'publish');
            }

            $now = $model->freshTimestamp();

            $this->newModel()->newQuery()
                ->where('name', $name)
                ->where('status', StoredDefinition::STATUS_PUBLISHED)
                ->update(['status' => StoredDefinition::STATUS_ARCHIVED, 'updated_at' => $now]);

            $model->forceFill([
                'published_at' => $now,
                'status' => StoredDefinition::STATUS_PUBLISHED,
            ])->save();

            return $this->toStoredDefinition($model->refresh());
        });
    }

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
