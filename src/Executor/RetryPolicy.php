<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\Attributes\Retry;

/**
 * Pure, framework-free resolution of a node's effective retry policy from its
 * `#[Retry]` attribute plus an optional per-node `config['retry']` override.
 *
 * `tries` is the total number of attempts (NOT a retry count); it is clamped to
 * a minimum of 1 — a deliberate divergence from Laravel's job-level "0 =
 * unlimited" so a poison node cannot loop forever. `backoff` is seconds: an int
 * for a fixed delay, or a list for a per-attempt schedule clamped to its last
 * value. Exhaustion (`isExhausted`) drives the node to `dead_letter`.
 *
 * @api
 */
final class RetryPolicy
{
    /**
     * @param  int|list<int>  $backoff
     */
    private function __construct(
        private readonly int $tries,
        private readonly int|array $backoff,
        private readonly int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $configOverride  keys: tries, backoff, timeout
     */
    public static function fromAttribute(?Retry $attribute, array $configOverride = []): self
    {
        if ($attribute === null) {
            return self::build(1, 0, 0, $configOverride);
        }

        return self::build($attribute->tries, $attribute->backoff, $attribute->timeout, $configOverride);
    }

    /**
     * Re-derive this policy with a per-node `config['retry']` override applied
     * on top of the current (attribute-derived) values.
     *
     * @param  array<string, mixed>  $configOverride  keys: tries, backoff, timeout
     */
    public function withConfig(array $configOverride): self
    {
        if ($configOverride === []) {
            return $this;
        }

        return self::build($this->tries, $this->backoff, $this->timeout, $configOverride);
    }

    /**
     * @param  int|list<int>  $backoff
     * @param  array<string, mixed>  $configOverride
     */
    private static function build(int $tries, int|array $backoff, int $timeout, array $configOverride): self
    {
        if (array_key_exists('tries', $configOverride) && is_int($configOverride['tries'])) {
            $tries = $configOverride['tries'];
        }

        if (array_key_exists('backoff', $configOverride) && (is_int($configOverride['backoff']) || is_array($configOverride['backoff']))) {
            /** @var int|list<int> $backoff */
            $backoff = $configOverride['backoff'];
        }

        if (array_key_exists('timeout', $configOverride) && is_int($configOverride['timeout'])) {
            $timeout = $configOverride['timeout'];
        }

        return new self(max(1, $tries), self::normalizeBackoff($backoff), max(0, $timeout));
    }

    public function tries(): int
    {
        return $this->tries;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Backoff (seconds) to wait BEFORE the attempt numbered `$attempt` (1-based).
     */
    public function backoffForAttempt(int $attempt): int
    {
        if (is_int($this->backoff)) {
            return $this->backoff;
        }

        if ($this->backoff === []) {
            return 0;
        }

        $index = max(1, $attempt) - 1;

        return $this->backoff[$index] ?? $this->backoff[array_key_last($this->backoff)];
    }

    /**
     * True once `$attempts` attempts have been made and no more remain.
     */
    public function isExhausted(int $attempts): bool
    {
        return $attempts >= $this->tries;
    }

    /**
     * @param  int|list<int>  $backoff
     * @return int|list<int>
     */
    private static function normalizeBackoff(int|array $backoff): int|array
    {
        if (is_int($backoff)) {
            return max(0, $backoff);
        }

        return array_values(array_map(static fn (int $seconds): int => max(0, $seconds), $backoff));
    }
}
