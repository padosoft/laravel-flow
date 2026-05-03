<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Padosoft\LaravelFlow\FlowRun;

/**
 * @property string $id
 * @property string $definition_name
 * @property string $status
 * @property bool $dry_run
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $business_impact
 * @property string|null $failed_step
 * @property bool $compensated
 * @property string|null $compensation_status
 * @property string|null $correlation_id
 * @property string|null $idempotency_key
 * @property int|null $duration_ms
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $finished_at
 */
final class FlowRunRecord extends Model
{
    protected $table = 'flow_runs';

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
        'business_impact' => 'array',
        'compensated' => 'boolean',
        'created_at' => 'immutable_datetime',
        'duration_ms' => 'integer',
        'dry_run' => 'boolean',
        'finished_at' => 'immutable_datetime',
        'input' => 'array',
        'output' => 'array',
        'started_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<FlowStepRecord, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(FlowStepRecord::class, 'run_id', 'id');
    }

    public static function pendingStatus(): string
    {
        return FlowRun::STATUS_PENDING;
    }
}
