<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use Padosoft\LaravelFlow\Node\NodeRegistry;
use RuntimeException;

/**
 * Thrown when {@see NodeRegistry::get()} is called with an unregistered node type.
 *
 * @api
 */
final class UnknownNodeTypeException extends RuntimeException {}
