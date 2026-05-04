<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

interface ApprovalRepository
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPending(
        string $id,
        string $runId,
        string $stepName,
        string $tokenHash,
        DateTimeInterface $expiresAt,
        array $payload = [],
    ): FlowApprovalRecord;

    public function findPendingByTokenHash(string $tokenHash): ?FlowApprovalRecord;

    public function findByTokenHash(string $tokenHash): ?FlowApprovalRecord;

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    public function consumePending(
        string $tokenHash,
        string $status,
        array $actor = [],
        array $payload = [],
        ?DateTimeInterface $decidedAt = null,
    ): ?FlowApprovalRecord;

    public function expirePending(string $tokenHash, DateTimeInterface $decidedAt): ?FlowApprovalRecord;
}
