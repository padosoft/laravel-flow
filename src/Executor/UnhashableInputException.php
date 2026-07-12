<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use RuntimeException;

/**
 * Raised when a node's inputs/config contain an object or resource, which have
 * no stable canonical JSON form (two distinct objects can both serialize to
 * `{}`, colliding to the same content hash). {@see NodeExecutor} catches this
 * and skips caching for that node execution, so an unhashable payload degrades
 * to a normal (uncached) run rather than risking a wrong cache hit.
 *
 * @internal
 */
final class UnhashableInputException extends RuntimeException {}
