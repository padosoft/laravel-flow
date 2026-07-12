<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $content_hash
 * @property string $node_type
 * @property array<string, mixed> $outputs
 * @property array<string, mixed>|null $business_impact
 * @property \DateTimeInterface|null $expires_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 *
 * @internal
 */
final class FlowNodeCacheRecord extends Model
{
    protected $table = 'flow_node_cache';

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
        'expires_at' => 'immutable_datetime',
        'outputs' => 'array',
        'updated_at' => 'immutable_datetime',
    ];
}
