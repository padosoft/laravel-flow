<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph\Exceptions;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use RuntimeException;

/**
 * Thrown when a flow definition name/version pair has no matching row
 * in the {@see DefinitionRepository}.
 *
 * @api
 */
final class DefinitionNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $name, public readonly int $version)
    {
        parent::__construct("Flow definition [{$name}] version [{$version}] was not found.");
    }
}
