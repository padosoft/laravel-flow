<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $run_id
 * @property string $step_name
 * @property string $status
 * @property string $token_hash
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $actor
 * @property Carbon|null $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $decided_at
 */
final class FlowApprovalRecord extends Model
{
    protected $table = 'flow_approvals';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor' => 'array',
            'consumed_at' => 'datetime',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
