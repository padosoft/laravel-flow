<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Contracts\Container\Container;
use Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

/**
 * Keeps singleton FlowStore repositories aligned with the current redactor binding.
 *
 * @internal
 */
final class ExecutionScopedPayloadRedactor implements CurrentPayloadRedactorProvider
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function redact(array $payload): array
    {
        return $this->currentRedactor()->redact($payload);
    }

    public function currentRedactor(): PayloadRedactor
    {
        /** @var PayloadRedactor $redactor */
        $redactor = $this->container->make(PayloadRedactor::class);

        if ($redactor instanceof self) {
            return $this->fallbackRedactor();
        }

        return PayloadRedactorResolution::current(
            $redactor,
            fn (CurrentPayloadRedactorProvider $provider): PayloadRedactor => $provider instanceof self
                ? $this->fallbackRedactor()
                : $provider->currentRedactor(),
        );
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
