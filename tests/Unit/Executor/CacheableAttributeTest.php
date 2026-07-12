<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use InvalidArgumentException;
use Padosoft\LaravelFlow\Executor\Attributes\Cacheable;
use PHPUnit\Framework\TestCase;

final class CacheableAttributeTest extends TestCase
{
    public function test_null_ttl_means_never_expires(): void
    {
        $this->assertNull((new Cacheable)->ttl);
        $this->assertNull((new Cacheable(null))->ttl);
    }

    public function test_positive_ttl_is_accepted(): void
    {
        $this->assertSame(60, (new Cacheable(60))->ttl);
    }

    public function test_zero_ttl_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cacheable(0);
    }

    public function test_negative_ttl_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cacheable(-5);
    }
}
