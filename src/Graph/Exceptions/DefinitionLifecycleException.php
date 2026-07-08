<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph\Exceptions;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use RuntimeException;

/**
 * Thrown when a definition lifecycle transition (publish/archive) is
 * attempted from a status that does not allow it: draft -> published,
 * draft|published -> archived. Published rows are immutable — the only
 * way to change a graph is {@see DefinitionRepository::createDraft()}.
 *
 * @api
 */
final class DefinitionLifecycleException extends RuntimeException
{
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly string $currentStatus,
        public readonly string $attemptedTransition,
    ) {
        parent::__construct(
            "Flow definition [{$name}] version [{$version}] cannot [{$attemptedTransition}] from status [{$currentStatus}]."
        );
    }
}
