<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Queue;

/**
 * @internal
 */
final readonly class QueueRetryPolicy
{
    /**
     * @param  int|list<int>|null  $backoffSeconds
     */
    public function __construct(
        public ?int $tries = null,
        public int|array|null $backoffSeconds = null,
    ) {}

    /**
     * @param  array{tries?: mixed, backoff_seconds?: mixed}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            tries: self::normalizeTries($config['tries'] ?? null),
            backoffSeconds: self::normalizeBackoffSeconds($config['backoff_seconds'] ?? null),
        );
    }

    public static function normalizeTries(mixed $tries): ?int
    {
        $tries = self::integerValue($tries);

        if ($tries === null) {
            return null;
        }

        return $tries >= 0 ? $tries : null;
    }

    /**
     * @return int|list<int>|null
     */
    public static function normalizeBackoffSeconds(mixed $backoffSeconds): int|array|null
    {
        $integerBackoffSeconds = self::integerValue($backoffSeconds);

        if ($integerBackoffSeconds !== null) {
            return $integerBackoffSeconds >= 0 ? $integerBackoffSeconds : null;
        }

        if (is_string($backoffSeconds)) {
            $backoffSeconds = explode(',', $backoffSeconds);
        }

        if (! is_array($backoffSeconds)) {
            return null;
        }

        $seconds = [];

        foreach ($backoffSeconds as $second) {
            $second = self::integerValue($second);

            if ($second !== null && $second >= 0) {
                $seconds[] = $second;
            }
        }

        return $seconds === [] ? null : $seconds;
    }

    public function canRetryWholeRun(): bool
    {
        if ($this->tries === 0 || ($this->tries !== null && $this->tries > 1)) {
            return true;
        }

        return $this->tries !== 1 && $this->backoffSeconds !== null;
    }

    private static function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (! preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
