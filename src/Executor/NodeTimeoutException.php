<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use RuntimeException;

/**
 * Raised when a node's successful attempt overran its configured `timeout`
 * (post-hoc wall-clock check in the synchronous runner). Surfaces as the failed
 * attempt's error so the retry/dead-letter machinery treats it like any other
 * failure.
 *
 * @internal
 */
final class NodeTimeoutException extends RuntimeException {}
