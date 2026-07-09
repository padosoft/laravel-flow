<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\DefinitionSigner;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
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
        private readonly DefinitionSigner $signer = new DefinitionSigner,
    ) {}

    public function createDraft(string $name, GraphDefinition $graph): StoredDefinition
    {
        $payload = $this->serializer->toArray($graph);
        $checksum = $this->serializer->checksum($graph);
        $signature = $this->signer->sign($checksum);

        return $this->withLockedLatestVersion(
            $name,
            fn (?FlowDefinitionRecord $latest): StoredDefinition => $this->insertNextDraftVersion($name, $latest, $payload, $checksum, $signature),
        );
    }

    /**
     * Atomic checksum dedupe for `persist_registered`-style callers: the
     * comparison against the latest stored version and the insert of the
     * next version both happen inside the SAME name-group lock acquired
     * by {@see self::withLockedLatestVersion()}, so two workers registering
     * the same unchanged flow concurrently cannot both observe "unchanged"
     * and each create a duplicate draft version — the second one blocks on
     * the lock until the first commits, then re-reads the (now current)
     * latest checksum. Not reproducible on sqlite: the whole-connection
     * write serialization used by the test suite already forces the two
     * writers to run sequentially rather than interleaved, so there is no
     * window in which both could observe the pre-insert state (see
     * DefinitionRepositoryTest).
     *
     * @throws DefinitionSignatureException
     */
    public function createDraftIfChanged(string $name, GraphDefinition $graph): ?StoredDefinition
    {
        $payload = $this->serializer->toArray($graph);
        $checksum = $this->serializer->checksum($graph);
        $signature = $this->signer->sign($checksum);

        return $this->withLockedLatestVersion(
            $name,
            function (?FlowDefinitionRecord $latest) use ($name, $payload, $checksum, $signature): ?StoredDefinition {
                // Mirrors toStoredDefinition()'s checksum resolution: while
                // signing is enabled the raw column is unsigned and could
                // have been tampered independently of graph+signature, so
                // the comparison must use the checksum recomputed (and
                // signature-verified) from the stored graph, not the column.
                if ($latest !== null && ($this->verifySignature($latest) ?? $latest->checksum) === $checksum) {
                    return null;
                }

                return $this->insertNextDraftVersion($name, $latest, $payload, $checksum, $signature);
            },
        );
    }

    /**
     * Runs $body inside a transaction holding lockForUpdate() over every
     * row of $name, passing it the locked latest version (or null when
     * none exists yet), and retries up to 3 attempts total on unique
     * constraint violations.
     *
     * Bounded retry: the lock above serializes drafts of an EXISTING name,
     * but for a brand-new name the lock query returns (and therefore
     * locks) zero rows on engines like Postgres, so two first drafts can
     * both compute version 1. The unique(name,version) index makes the
     * loser fail cleanly; retrying re-resolves the latest version and
     * therefore the next version number. Not reproducible on sqlite
     * (single writer) — by design.
     *
     * @template T
     *
     * @param  Closure(?FlowDefinitionRecord): T  $body
     * @return T
     */
    private function withLockedLatestVersion(string $name, Closure $body): mixed
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::connection($this->connection)->transaction(function () use ($name, $body) {
                    $group = $this->newModel()->newQuery()->where('name', $name)->lockForUpdate()->get();

                    $latest = null;

                    // foreach+instanceof instead of a Collection callback:
                    // same Larastan model-type-inference gap noted on
                    // publish()/versions() below.
                    foreach ($group as $record) {
                        if ($record instanceof FlowDefinitionRecord && ($latest === null || $record->version > $latest->version)) {
                            $latest = $record;
                        }
                    }

                    return $body($latest);
                });
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertNextDraftVersion(string $name, ?FlowDefinitionRecord $latest, array $payload, string $checksum, ?string $signature): StoredDefinition
    {
        $model = $this->newModel();
        $model->forceFill([
            'checksum' => $checksum,
            'graph' => $payload,
            'name' => $name,
            'signature' => $signature,
            'status' => StoredDefinition::STATUS_DRAFT,
            'version' => $latest !== null ? $latest->version + 1 : 1,
        ])->save();

        return $this->toStoredDefinition($model->refresh());
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

    /**
     * Returns the checksum recomputed from the stored graph when signing
     * is enabled, null when disabled (no recompute on the fast path).
     *
     * @throws DefinitionSignatureException when signing is enabled and the
     *                                      recomputed checksum does not verify against the stored signature
     */
    private function verifySignature(FlowDefinitionRecord $model): ?string
    {
        if (! $this->signer->isEnabled()) {
            // Skips the fromArray()/checksum() recompute entirely: while
            // disabled, verification is a no-op regardless of whether a
            // signature is present (see DefinitionSigner's tolerant-read
            // design decision), so there is nothing worth computing here.
            return null;
        }

        try {
            $checksum = $this->serializer->checksum($this->serializer->fromArray($model->graph));
        } catch (\Throwable $e) {
            // With signing enabled, an unreadable stored graph IS a
            // verification failure: surface it under the documented
            // contract exception instead of leaking serializer errors.
            throw new DefinitionSignatureException($model->name, $model->version, previous: $e);
        }

        if (! $this->signer->verify($checksum, $model->signature)) {
            throw new DefinitionSignatureException($model->name, $model->version);
        }

        return $checksum;
    }

    private function toStoredDefinition(FlowDefinitionRecord $model): StoredDefinition
    {
        $verifiedChecksum = $this->verifySignature($model);

        return new StoredDefinition(
            id: $model->id,
            name: $model->name,
            version: $model->version,
            status: $model->status,
            graph: $model->graph,
            // With signing enabled, return the checksum recomputed from the
            // verified graph: the raw column is unsigned and could have been
            // tampered independently of graph+signature.
            checksum: $verifiedChecksum ?? $model->checksum,
            signature: $model->signature,
            publishedAt: $model->published_at instanceof DateTimeImmutable ? $model->published_at : null,
        );
    }

    private function newModel(): FlowDefinitionRecord
    {
        return (new FlowDefinitionRecord)->setConnection($this->connection);
    }
}
