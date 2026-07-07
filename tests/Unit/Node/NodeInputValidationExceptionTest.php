<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;
use PHPUnit\Framework\TestCase;

final class NodeInputValidationExceptionTest extends TestCase
{
    public function test_message_keeps_violation_detail_when_a_violation_contains_invalid_utf8(): void
    {
        $violations = [
            '_unknown' => ["Unknown input port [\xB1\x31]."],
        ];

        $exception = new NodeInputValidationException($violations);

        $this->assertStringContainsString('Node input validation failed:', $exception->getMessage());
        $this->assertNotSame('Node input validation failed: ', $exception->getMessage());
    }
}
