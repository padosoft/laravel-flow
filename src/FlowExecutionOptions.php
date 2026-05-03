<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Padosoft\LaravelFlow\Exceptions\FlowInputException;

final readonly class FlowExecutionOptions
{
    public const MAX_IDENTIFIER_LENGTH = 255;

    public ?string $correlationId;

    public ?string $idempotencyKey;

    public function __construct(?string $correlationId = null, ?string $idempotencyKey = null)
    {
        $this->correlationId = $this->normalize($correlationId, 'correlation id');
        $this->idempotencyKey = $this->normalize($idempotencyKey, 'idempotency key');
    }

    public static function make(?string $correlationId = null, ?string $idempotencyKey = null): self
    {
        return new self($correlationId, $idempotencyKey);
    }

    private function normalize(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > self::MAX_IDENTIFIER_LENGTH) {
            throw new FlowInputException(sprintf(
                'Flow execution %s may not exceed %d characters.',
                $field,
                self::MAX_IDENTIFIER_LENGTH,
            ));
        }

        return $value;
    }
}
