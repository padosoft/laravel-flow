<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

/**
 * Paginated dashboard result. The companion app uses `total` to render
 * pagination controls without needing a second COUNT query.
 *
 * @template TItem
 */
final readonly class PaginatedResult
{
    /**
     * @param  list<TItem>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function totalPages(): int
    {
        if ($this->perPage < 1) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }
}
