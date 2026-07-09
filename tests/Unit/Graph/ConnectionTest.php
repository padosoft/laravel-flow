<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function test_holds_endpoints_and_identity(): void
    {
        $wire = new Connection('a', 'output', 'b', 'input');

        $this->assertSame('a', $wire->sourceNodeId);
        $this->assertSame('output', $wire->sourcePortKey);
        $this->assertSame('b', $wire->targetNodeId);
        $this->assertSame('input', $wire->targetPortKey);
        $this->assertSame('a.output>b.input', $wire->identity());
    }

    public function test_rejects_blank_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Connection('a', '', 'b', 'input');
    }

    public function test_rejects_self_loop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/itself/i');

        new Connection('a', 'output', 'a', 'input');
    }
}
