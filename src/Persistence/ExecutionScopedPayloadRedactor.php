<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Contracts\Container\Container;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

/**
 * Keeps singleton FlowStore repositories aligned with the current redactor binding.
 */
final class ExecutionScopedPayloadRedactor implements PayloadRedactor
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function redact(array $payload): array
    {
        $redactor = $this->currentRedactor();

        return $redactor->redact($payload);
    }

    private function currentRedactor(): PayloadRedactor
    {
        /** @var PayloadRedactor $redactor */
        $redactor = $this->container->make(PayloadRedactor::class);

        if ($redactor === $this) {
            return $this->fallbackRedactor();
        }

        return $redactor;
    }

    private function fallbackRedactor(): PayloadRedactor
    {
        /** @var array<string, mixed> $redaction */
        $redaction = $this->container['config']->get('laravel-flow.persistence.redaction', []);

        return new KeyBasedPayloadRedactor(
            enabled: (bool) ($redaction['enabled'] ?? true),
            keys: array_values(array_filter((array) ($redaction['keys'] ?? []), 'is_string')),
            replacement: (string) ($redaction['replacement'] ?? '[redacted]'),
        );
    }
}
