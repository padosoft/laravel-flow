<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

/**
 * Optional repository extension required by persisted approval resume/reject.
 *
 * @api
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

    /**
     * Reissue a downstream pending approval token exactly once.
     *
     * Implementations must only reissue while the current pending token is still unexpired at
     * $issuedAt, preserve the previous hash as an accepted lookup/consume hash, and make the
     * matching ApprovalRepository lookup/expire paths honor both the current and previous hashes.
     */
    public function reissuePendingTokenForStep(
        string $runId,
        string $stepName,
        string $tokenHash,
        DateTimeInterface $expiresAt,
        DateTimeInterface $issuedAt,
    ): ?FlowApprovalRecord;
}
