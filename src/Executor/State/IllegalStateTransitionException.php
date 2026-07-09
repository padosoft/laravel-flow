<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\State;

use LogicException;

/**
 * @api
 */
final class IllegalStateTransitionException extends LogicException
{
    public static function for(string $enum, string $from, string $to): self
    {
        return new self("Illegal {$enum} transition [{$from}] → [{$to}].");
    }
}
