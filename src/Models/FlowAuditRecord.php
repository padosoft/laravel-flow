<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string|null $run_id
 * @property string|null $step_name
 * @property string $event
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $business_impact
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
