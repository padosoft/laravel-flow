<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Exceptions;

use RuntimeException;

/**
 * Base exception for every laravel-flow failure mode.
 *
 * NOT final on purpose — package + host applications subclass it
 * (W4.C lesson — `final` on the parent prevents typed catch upstream).
 */
class FlowException extends RuntimeException {}
