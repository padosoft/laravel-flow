<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Stubs;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Padosoft\LaravelFlow\Contracts\DashboardReadScope;

/**
 * A read scope that records which tables it was offered and applies a
 * per-table constraint.
 *
 * Recording is what lets a test assert the scope reaches every base query
 * rather than only the run list — the distinction that matters, because a
 * scope applied to the list alone still lets a caller read an excluded run's
 * detail, and the detail is where the payloads are.
 */
final class RecordingReadScope implements DashboardReadScope
{
    /** @var list<string> */
    public array $tables = [];

    /** @param  array<string, Closure>  $constraints  keyed by table name */
    public function __construct(private readonly array $constraints = []) {}

    public function apply(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        $this->tables[] = $table;

        $constraint = $this->constraints[$table] ?? null;

        return $constraint === null ? $query : $constraint($query);
    }
}
