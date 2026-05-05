<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $run_id
 * @property string $step_name
 * @property string $status
 * @property string $token_hash
 * @property string|null $previous_token_hash
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $actor
 * @property \DateTimeInterface|null $expires_at
 * @property \DateTimeInterface|null $consumed_at
 * @property \DateTimeInterface|null $decided_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
final class FlowApprovalRecord extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'flow_approvals';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'actor' => 'array',
        'consumed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'payload' => 'array',
        'updated_at' => 'immutable_datetime',
    ];
}
