<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;

/**
 * @extends Builder<FlowAuditRecord>
 */
final class AppendOnlyAuditBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw new LogicException('Flow audit records are append-only and cannot be updated.');
    }

    public function delete(): mixed
    {
        throw new LogicException('Flow audit records are append-only and cannot be deleted.');
    }

    public function forceDelete(): mixed
    {
        throw new LogicException('Flow audit records are append-only and cannot be deleted.');
    }
}
