<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $run_id
 * @property string $parent_node_id
 * @property string|null $child_run_id
 * @property int $child_index
 * @property string $status
 * @property string $child_flow
 * @property int|null $child_version
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $outputs
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $finished_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 *
 * @internal
 */
final class FlowNodeChildRecord extends Model
{
    protected $table = 'flow_node_children';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'child_index' => 'integer',
        'child_version' => 'integer',
        'created_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'input' => 'array',
        'outputs' => 'array',
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
