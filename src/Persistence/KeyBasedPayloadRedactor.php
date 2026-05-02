<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

final class KeyBasedPayloadRedactor implements PayloadRedactor
{
    /**
     * @var array<string, true>
     */
    private array $keys;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        private readonly bool $enabled = true,
        array $keys = [],
        private readonly string $replacement = '[redacted]',
    ) {
        $this->keys = [];

        foreach ($keys as $key) {
            $this->keys[$this->normalize($key)] = true;
        }
    }

    public function redact(array $payload): array
    {
        if (! $this->enabled || $this->keys === []) {
            return $payload;
        }

        return $this->redactArray($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($this->shouldRedact((string) $key)) {
                $payload[$key] = $this->replacement;

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $payload[$key] = $this->redactArray($nested);
            }
        }

        return $payload;
    }

    private function shouldRedact(string $key): bool
    {
        return isset($this->keys[$this->normalize($key)]);
    }

    private function normalize(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
    }
}
