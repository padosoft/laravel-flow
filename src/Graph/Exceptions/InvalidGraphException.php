<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a graph violates structural or semantic invariants.
 * Carries the full violation list so tooling (Studio, importers) can
 * surface every problem at once instead of fix-one-rerun loops.
 *
 * @api
 */
final class InvalidGraphException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Invalid graph: '.implode(' | ', $violations));
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
