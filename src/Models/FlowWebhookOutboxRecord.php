<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $run_id
 * @property string|null $approval_id
 * @property string $event
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property string|null $last_error
 */
final class FlowWebhookOutboxRecord extends Model
{
    protected $table = 'flow_webhook_outbox';

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
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'max_attempts' => 'integer',
            'payload' => 'array',
        ];
    }
}
