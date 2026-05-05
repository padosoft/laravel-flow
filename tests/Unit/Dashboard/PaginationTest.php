<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Dashboard;

use InvalidArgumentException;
use Padosoft\LaravelFlow\Dashboard\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function test_offset_is_computed_from_page_and_per_page(): void
    {
        $pagination = new Pagination(page: 3, perPage: 25);
        $this->assertSame(50, $pagination->offset());
    }

    public function test_constructor_rejects_zero_or_negative_page(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Pagination(page: 0);
    }

    public function test_constructor_rejects_per_page_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Pagination(page: 1, perPage: 0);
    }

    public function test_constructor_rejects_per_page_above_max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Pagination(page: 1, perPage: Pagination::MAX_PER_PAGE + 1);
    }
}
