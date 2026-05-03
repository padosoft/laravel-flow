<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use PHPUnit\Framework\TestCase;

final class FlowExecutionOptionsTest extends TestCase
{
    public function test_normalizes_trimmed_and_blank_identifiers(): void
    {
        $options = FlowExecutionOptions::make(
            correlationId: '  corr-123  ',
            idempotencyKey: "\tidentity-123\n",
        );

        $blankOptions = FlowExecutionOptions::make(
            correlationId: '   ',
            idempotencyKey: "\t\n",
        );

        $this->assertSame('corr-123', $options->correlationId);
        $this->assertSame('identity-123', $options->idempotencyKey);
        $this->assertNull($blankOptions->correlationId);
        $this->assertNull($blankOptions->idempotencyKey);
    }

    public function test_accepts_multibyte_identifiers_up_to_schema_character_limit(): void
    {
        $identifier = str_repeat("\u{00E9}", FlowExecutionOptions::MAX_IDENTIFIER_LENGTH);

        $options = FlowExecutionOptions::make(
            correlationId: $identifier,
            idempotencyKey: $identifier,
        );

        $this->assertSame($identifier, $options->correlationId);
        $this->assertSame($identifier, $options->idempotencyKey);
    }

    public function test_rejects_oversized_correlation_id(): void
    {
        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('Flow execution correlation id may not exceed 255 characters.');

        FlowExecutionOptions::make(correlationId: str_repeat('c', FlowExecutionOptions::MAX_IDENTIFIER_LENGTH + 1));
    }

    public function test_rejects_oversized_idempotency_key(): void
    {
        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('Flow execution idempotency key may not exceed 255 characters.');

        FlowExecutionOptions::make(idempotencyKey: str_repeat('i', FlowExecutionOptions::MAX_IDENTIFIER_LENGTH + 1));
    }
}
