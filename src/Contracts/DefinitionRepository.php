<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\StoredDefinition;

/**
 * Versioned storage for {@see GraphDefinition} graphs: draft versions are
 * created explicitly, published versions are immutable, and archiving is
 * terminal. There is no update-graph API — a change is always a new draft
 * version via {@see self::createDraft()}.
 *
 * @api
 */
interface DefinitionRepository
{
    /**
     * Creates version = max(existing)+1 (or 1) for $name, in status 'draft'.
     */
    public function createDraft(string $name, GraphDefinition $graph): StoredDefinition;

    /**
     * @throws DefinitionNotFoundException
     */
    public function find(string $name, int $version): StoredDefinition;

    /**
     * Latest version by number; optionally constrained to a status.
     * Null when no matching version exists.
     */
    public function latest(string $name, ?string $status = null): ?StoredDefinition;

    /**
     * draft -> published; archives any previously published version of
     * the same name.
     *
     * @throws DefinitionLifecycleException when the version is not a draft
     */
    public function publish(string $name, int $version): StoredDefinition;

    /**
     * draft|published -> archived.
     *
     * @throws DefinitionLifecycleException when the version is already archived
     */
    public function archive(string $name, int $version): StoredDefinition;

    /**
     * @return list<StoredDefinition> all versions of $name, ascending
     */
    public function versions(string $name): array;
}
