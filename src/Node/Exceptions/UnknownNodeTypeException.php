<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use RuntimeException;

/**
 * Thrown when the node registry is asked for an unregistered node type.
 *
 * @api
 */
final class UnknownNodeTypeException extends RuntimeException {}
