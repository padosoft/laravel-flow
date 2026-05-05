<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use InvalidArgumentException;

/**
 * Pagination request DTO for dashboard list queries. The constructor
 * normalizes invalid input by throwing instead of silently coercing
 * because the dashboard contract treats list pagination as load-bearing.
 *
 * @api
 */
final readonly class Pagination
{
    public const DEFAULT_PAGE = 1;

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 200;

    public function __construct(
        public int $page = self::DEFAULT_PAGE,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException(sprintf('Pagination page must be >= 1, got [%d].', $page));
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'Pagination perPage must be between 1 and %d, got [%d].',
                self::MAX_PER_PAGE,
                $perPage,
            ));
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
