<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a class cannot be turned into a valid NodeDefinition.
 *
 * @api
 */
final class InvalidNodeDefinitionException extends InvalidArgumentException {}
