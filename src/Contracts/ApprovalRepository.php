<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

/**
 * @api
 */
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

    /**
     * Expire EVERY still-pending approval belonging to a run, returning how
     * many rows were expired. Used when a run reaches a terminal state it can
     * never resume from (e.g. a cancellation), so a still-pending approval can
     * never be decided against a dead run. Unlike {@see self::expirePending()}
     * (keyed by a single token hash), this is keyed by run id and is a bulk,
     * idempotent no-op when the run has no pending approvals.
     */
    public function expirePendingForRun(string $runId, DateTimeInterface $decidedAt): int;
}
