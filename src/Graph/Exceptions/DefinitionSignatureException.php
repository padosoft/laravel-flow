<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph\Exceptions;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use RuntimeException;

/**
 * Thrown by {@see DefinitionRepository} read paths when signing is enabled
 * (`laravel-flow.definitions.signing_secret` is configured) and a stored
 * definition's recomputed checksum does not verify against its persisted
 * `signature` column — for example, the `graph` column was edited outside
 * the repository.
 *
 * @api
 */
final class DefinitionSignatureException extends RuntimeException
{
    public function __construct(public readonly string $name, public readonly int $version, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Flow definition [{$name}] version [{$version}] failed signature verification.",
            previous: $previous,
        );
    }
}
