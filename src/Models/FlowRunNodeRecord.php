<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $run_id
 * @property int|null $sequence
 * @property string $node_id
 * @property string $node_type
 * @property string|null $handler
 * @property string $status
 * @property int $attempts
 * @property array<string, mixed>|null $inputs
 * @property array<string, mixed>|null $outputs
 * @property array<string, mixed>|null $business_impact
 * @property string|null $error_class
 * @property string|null $error_message
 * @property bool $dry_run_skipped
 * @property string|null $cache_hit
 * @property int|null $duration_ms
 * @property \DateTimeInterface|null $available_at
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $finished_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 *
 * @internal
 */
final class FlowRunNodeRecord extends Model
{
    protected $table = 'flow_run_nodes';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'business_impact' => 'array',
        'created_at' => 'immutable_datetime',
        'duration_ms' => 'integer',
        'dry_run_skipped' => 'boolean',
        'finished_at' => 'immutable_datetime',
        'inputs' => 'array',
        'outputs' => 'array',
        'sequence' => 'integer',
        'started_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<FlowRunRecord, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(FlowRunRecord::class, 'run_id', 'id');
    }
}
