<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

final readonly class FlowExecutionOptions
{
    public ?string $correlationId;

    public ?string $idempotencyKey;

    public function __construct(?string $correlationId = null, ?string $idempotencyKey = null)
    {
        $this->correlationId = $this->normalize($correlationId);
        $this->idempotencyKey = $this->normalize($idempotencyKey);
    }

    public static function make(?string $correlationId = null, ?string $idempotencyKey = null): self
    {
        return new self($correlationId, $idempotencyKey);
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
