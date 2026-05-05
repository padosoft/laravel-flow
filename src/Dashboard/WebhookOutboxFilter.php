<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeInterface;

/**
 * Filter DTO for the webhook outbox dashboard query. All fields are
 * optional; null means "do not constrain on this field". The status
 * field accepts one of `pending`, `delivering`, `delivered`, `failed`.
 * The event field matches lifecycle events such as `flow.completed`,
 * `flow.failed`, `flow.paused`, or `flow.resumed`.
 */
final readonly class WebhookOutboxFilter
{
    public function __construct(
        public ?string $status = null,
        public ?string $event = null,
        public ?string $runId = null,
        public ?string $approvalId = null,
        public ?DateTimeInterface $createdSince = null,
        public ?DateTimeInterface $createdUntil = null,
    ) {}
}
