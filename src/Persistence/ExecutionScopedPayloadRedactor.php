<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Fiber;
use Illuminate\Contracts\Container\Container;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

/**
 * Keeps Eloquent repository redaction aligned with the FlowEngine execution.
 */
final class ExecutionScopedPayloadRedactor implements PayloadRedactor
{
    /**
     * @var array<string, list<PayloadRedactor>>
     */
    private array $stacks = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function push(PayloadRedactor $redactor): void
    {
        $this->stacks[$this->scopeKey()][] = $redactor;
    }

    public function pop(): void
    {
        $key = $this->scopeKey();

        if (! isset($this->stacks[$key])) {
            return;
        }

        array_pop($this->stacks[$key]);

        if ($this->stacks[$key] === []) {
            unset($this->stacks[$key]);
        }
    }

    public function redact(array $payload): array
    {
        $redactor = $this->currentRedactor();

        if ($redactor === $this) {
            return $payload;
        }

        return $redactor->redact($payload);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function usingCurrentRedactor(callable $callback): mixed
    {
        $this->push($this->currentRedactor());

        try {
            return $callback();
        } finally {
            $this->pop();
        }
    }

    private function currentRedactor(): PayloadRedactor
    {
        $stack = $this->stacks[$this->scopeKey()] ?? [];
        $scoped = end($stack);

        if ($scoped instanceof PayloadRedactor) {
            return $scoped;
        }

        /** @var PayloadRedactor $redactor */
        $redactor = $this->container->make(PayloadRedactor::class);

        return $redactor;
    }

    private function scopeKey(): string
    {
        $fiber = Fiber::getCurrent();

        return $fiber instanceof Fiber
            ? 'fiber:'.spl_object_id($fiber)
            : 'main';
    }
}
