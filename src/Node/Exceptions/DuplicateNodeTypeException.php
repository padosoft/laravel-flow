<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use Padosoft\LaravelFlow\Node\NodeRegistry;
use RuntimeException;

/**
 * Thrown when a node type is registered more than once in a {@see NodeRegistry}.
 *
 * @api
 */
final class DuplicateNodeTypeException extends RuntimeException {}
