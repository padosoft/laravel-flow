<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $run_id
 * @property int $sequence
 * @property string $step_name
 * @property string|null $handler
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $business_impact
 * @property string|null $error_class
 * @property string|null $error_message
 * @property bool $dry_run_skipped
 */
final class FlowStepRecord extends Model
{
    protected $table = 'flow_steps';

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
        'dry_run_skipped' => 'boolean',
        'finished_at' => 'immutable_datetime',
        'input' => 'array',
        'output' => 'array',
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
