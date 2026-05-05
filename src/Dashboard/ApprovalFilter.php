<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeInterface;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

/**
 * Filter DTO for the approval list dashboard query. All fields are
 * optional; null means "do not constrain on this field". The status
 * field accepts one of the {@see FlowApprovalRecord::STATUS_*}
 * constants (`pending`, `approved`, `rejected`, `expired`).
 */
final readonly class ApprovalFilter
{
    public function __construct(
        public ?string $status = null,
        public ?string $runId = null,
        public ?string $stepName = null,
        public ?DateTimeInterface $createdSince = null,
        public ?DateTimeInterface $createdUntil = null,
    ) {}
}
