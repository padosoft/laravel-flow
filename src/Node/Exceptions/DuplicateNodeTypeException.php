<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use RuntimeException;

/**
 * Thrown when a node type is registered more than once in the node registry.
 *
 * @api
 */
final class DuplicateNodeTypeException extends RuntimeException {}
