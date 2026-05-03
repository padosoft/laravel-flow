<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Contracts\Container\Container;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

/**
 * Keeps Eloquent repository redaction aligned with the FlowEngine execution.
 */
final class ExecutionScopedPayloadRedactor implements PayloadRedactor
{
    /**
     * @var list<PayloadRedactor>
     */
    private array $stack = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function push(PayloadRedactor $redactor): void
    {
        $this->stack[] = $redactor;
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    public function redact(array $payload): array
    {
        $redactor = $this->currentRedactor();

        if ($redactor === $this) {
            return $payload;
        }

        return $redactor->redact($payload);
    }

    private function currentRedactor(): PayloadRedactor
    {
        $scoped = end($this->stack);

        if ($scoped instanceof PayloadRedactor) {
            return $scoped;
        }

        /** @var PayloadRedactor $redactor */
        $redactor = $this->container->make(PayloadRedactor::class);

        return $redactor;
    }
}
