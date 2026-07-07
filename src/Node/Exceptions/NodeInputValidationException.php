<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use RuntimeException;

/**
 * Raised when node inputs violate the node's port contract. Carries
 * per-port violation messages; the reserved key `_unknown` groups
 * inputs that match no declared port.
 *
 * @api
 */
final class NodeInputValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Node input validation failed: '.json_encode($violations));
    }

    /**
     * @return array<string, list<string>>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
