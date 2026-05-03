<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Padosoft\LaravelFlow\Persistence\AppendOnlyAuditBuilder;

/**
 * @property int $id
 * @property string $run_id
 * @property string|null $step_name
 * @property string $event
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $business_impact
 * @property \DateTimeInterface|null $occurred_at
 * @property \DateTimeInterface|null $created_at
 */
final class FlowAuditRecord extends Model
{
    protected $table = 'flow_audit';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'business_impact' => 'array',
        'created_at' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
        'payload' => 'array',
    ];

    public function newEloquentBuilder($query): AppendOnlyAuditBuilder
    {
        /** @var QueryBuilder $query */
        return new AppendOnlyAuditBuilder($query);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Flow audit records are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Flow audit records are append-only and cannot be deleted.');
    }
}
