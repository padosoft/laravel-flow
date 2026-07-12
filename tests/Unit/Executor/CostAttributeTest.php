<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use InvalidArgumentException;
use Padosoft\LaravelFlow\Executor\Attributes\Cost;
use PHPUnit\Framework\TestCase;

final class CostAttributeTest extends TestCase
{
    public function test_valid_estimate_is_kept_as_declared(): void
    {
        $cost = new Cost(estimate: ['tokens' => 1200, 'cents' => 3.5]);

        $this->assertSame(['tokens' => 1200, 'cents' => 3.5], $cost->estimate);
    }

    public function test_empty_estimate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cost(estimate: []);
    }

    public function test_non_numeric_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Built dynamically so the runtime guard (not the static type) is what
        // rejects a non-numeric amount.
        /** @var array<string, int|float> $estimate */
        $estimate = json_decode('{"tokens":"many"}', true);

        new Cost(estimate: $estimate);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cost(estimate: ['cents' => -1]);
    }
}
