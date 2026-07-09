<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $graph
 * @property string $checksum
 * @property string|null $signature
 * @property \DateTimeInterface|null $published_at
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 *
 * @internal
 */
final class FlowDefinitionRecord extends Model
{
    protected $table = 'flow_definitions';

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
            'created_at' => 'immutable_datetime',
            'graph' => 'array',
            'published_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
