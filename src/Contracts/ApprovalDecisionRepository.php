<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

/**
 * Optional repository extension required by persisted approval resume/reject.
 */
interface ApprovalDecisionRepository
{
    public function findByTokenHash(string $tokenHash): ?FlowApprovalRecord;

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    public function consumePendingForRunStatus(
        string $tokenHash,
        string $status,
        string $runStatus,
        array $actor = [],
        array $payload = [],
        ?DateTimeInterface $decidedAt = null,
    ): ?FlowApprovalRecord;
}
