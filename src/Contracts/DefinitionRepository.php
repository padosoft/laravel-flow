<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\StoredDefinition;

/**
 * Versioned storage for {@see GraphDefinition} graphs: draft versions are
 * created explicitly, published versions are immutable, and archiving is
 * terminal. There is no update-graph API — a change is always a new draft
 * version via {@see self::createDraft()}.
 *
 * When definition signing is enabled (`laravel-flow.definitions.signing_secret`),
 * every method returning a {@see StoredDefinition} verifies the stored
 * graph's signature and throws {@see DefinitionSignatureException} on
 * mismatch.
 *
 * @api
 */
interface DefinitionRepository
{
    /**
     * Creates version = max(existing)+1 (or 1) for $name, in status 'draft'.
     *
     * @throws DefinitionSignatureException
     */
    public function createDraft(string $name, GraphDefinition $graph): StoredDefinition;

    /**
     * @throws DefinitionNotFoundException
     * @throws DefinitionSignatureException
     */
    public function find(string $name, int $version): StoredDefinition;

    /**
     * Latest version by number; optionally constrained to a status.
     * Null when no matching version exists.
     *
     * @throws DefinitionSignatureException
     */
    public function latest(string $name, ?string $status = null): ?StoredDefinition;

    /**
     * draft -> published; archives any previously published version of
     * the same name. The stored graph is rebuilt and semantically
     * validated against the current node catalog before the transition;
     * a draft may be semantically incomplete, but a published definition
     * must be executable.
     *
     * @throws DefinitionLifecycleException when the version is not a draft
     * @throws InvalidGraphException when the stored graph fails semantic validation
     * @throws DefinitionSignatureException
     */
    public function publish(string $name, int $version): StoredDefinition;

    /**
     * draft|published -> archived.
     *
     * @throws DefinitionLifecycleException when the version is already archived
     * @throws DefinitionSignatureException
     */
    public function archive(string $name, int $version): StoredDefinition;

    /**
     * @return list<StoredDefinition> all versions of $name, ascending
     *
     * @throws DefinitionSignatureException
     */
    public function versions(string $name): array;
}
