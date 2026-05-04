<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Padosoft\LaravelFlow\Contracts\ApprovalDecisionRepository;
use Padosoft\LaravelFlow\Contracts\ApprovalRepository;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

final class ApprovalTokenManager
{
    private const DEFAULT_TTL_MINUTES = 1440;

    /**
     * @var callable|null
     */
    private readonly mixed $clock;

    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly int $tokenTtlMinutes = self::DEFAULT_TTL_MINUTES,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function issue(string $runId, string $stepName, array $payload = []): IssuedApprovalToken
    {
        $plainTextToken = $this->generatePlainTextToken();
        $tokenHash = self::hashToken($plainTextToken);
        $expiresAt = $this->now()->modify(sprintf('+%d minutes', $this->ttlMinutes()));
        $approvalId = $this->generateId();

        $record = $this->approvals->createPending(
            id: $approvalId,
            runId: $runId,
            stepName: $stepName,
            tokenHash: $tokenHash,
            expiresAt: $expiresAt,
            payload: $payload,
        );

        return new IssuedApprovalToken(
            approvalId: $record->id,
            runId: $record->run_id,
            stepName: $record->step_name,
            plainTextToken: $plainTextToken,
            tokenHash: $record->token_hash,
            expiresAt: $this->immutableDate($record->expires_at) ?? $expiresAt,
        );
    }

    public function pending(string $plainTextToken): ?FlowApprovalRecord
    {
        return $this->pendingByHash(self::hashToken($plainTextToken), $this->now());
    }

    public function find(string $plainTextToken): ?FlowApprovalRecord
    {
        $now = $this->now();
        $tokenHash = self::hashToken($plainTextToken);
        $record = $this->approvalDecisions()->findByTokenHash($tokenHash);

        if (! $record instanceof FlowApprovalRecord) {
            return null;
        }

        $expiresAt = $this->immutableDate($record->expires_at);

        if ($record->status === FlowApprovalRecord::STATUS_PENDING
            && $expiresAt instanceof DateTimeImmutable
            && $expiresAt <= $now
        ) {
            return $this->approvals->expirePending($tokenHash, $now);
        }

        return $record;
    }

    private function pendingByHash(string $tokenHash, DateTimeImmutable $now): ?FlowApprovalRecord
    {
        $record = $this->approvals->findPendingByTokenHash($tokenHash);

        if (! $record instanceof FlowApprovalRecord) {
            return null;
        }

        $expiresAt = $this->immutableDate($record->expires_at);

        if ($expiresAt instanceof DateTimeImmutable && $expiresAt <= $now) {
            $this->approvals->expirePending($tokenHash, $now);

            return null;
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    public function approve(string $plainTextToken, array $actor = [], array $payload = []): ?FlowApprovalRecord
    {
        return $this->consume($plainTextToken, FlowApprovalRecord::STATUS_APPROVED, $actor, $payload);
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    public function approveForRunStatus(
        string $plainTextToken,
        string $runStatus,
        array $actor = [],
        array $payload = [],
    ): ?FlowApprovalRecord {
        return $this->consume($plainTextToken, FlowApprovalRecord::STATUS_APPROVED, $actor, $payload, $runStatus);
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    public function reject(string $plainTextToken, array $actor = [], array $payload = []): ?FlowApprovalRecord
    {
        return $this->consume($plainTextToken, FlowApprovalRecord::STATUS_REJECTED, $actor, $payload);
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    public function rejectForRunStatus(
        string $plainTextToken,
        string $runStatus,
        array $actor = [],
        array $payload = [],
    ): ?FlowApprovalRecord {
        return $this->consume($plainTextToken, FlowApprovalRecord::STATUS_REJECTED, $actor, $payload, $runStatus);
    }

    public function expireIssued(IssuedApprovalToken $token, ?DateTimeInterface $decidedAt = null): ?FlowApprovalRecord
    {
        return $this->approvals->expirePending($token->tokenHash, $this->immutableDate($decidedAt) ?? $this->now());
    }

    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    /**
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $payload
     */
    private function consume(
        string $plainTextToken,
        string $status,
        array $actor,
        array $payload,
        ?string $runStatus = null,
    ): ?FlowApprovalRecord {
        if (! in_array($status, [FlowApprovalRecord::STATUS_APPROVED, FlowApprovalRecord::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported approval decision status [%s].', $status));
        }

        $tokenHash = self::hashToken($plainTextToken);
        $now = $this->now();

        if (! $this->pendingByHash($tokenHash, $now) instanceof FlowApprovalRecord) {
            return null;
        }

        $record = $runStatus === null
            ? $this->approvals->consumePending(
                tokenHash: $tokenHash,
                status: $status,
                actor: $actor,
                payload: $payload,
                decidedAt: $now,
            )
            : $this->approvalDecisions()->consumePendingForRunStatus(
                tokenHash: $tokenHash,
                status: $status,
                runStatus: $runStatus,
                actor: $actor,
                payload: $payload,
                decidedAt: $now,
            );

        if ($record instanceof FlowApprovalRecord) {
            return $record;
        }

        return $this->expirePendingIfExpired($tokenHash, $now);
    }

    private function approvalDecisions(): ApprovalDecisionRepository
    {
        if (! ($this->approvals instanceof ApprovalDecisionRepository)) {
            throw new FlowExecutionException(sprintf(
                'Approval resume/reject requires the approval repository to implement %s.',
                ApprovalDecisionRepository::class,
            ));
        }

        return $this->approvals;
    }

    private function expirePendingIfExpired(string $tokenHash, DateTimeImmutable $now): ?FlowApprovalRecord
    {
        if (! ($this->approvals instanceof ApprovalDecisionRepository)) {
            return $this->approvals->expirePending($tokenHash, $now);
        }

        $current = $this->approvals->findByTokenHash($tokenHash);

        if (! $current instanceof FlowApprovalRecord || $current->status !== FlowApprovalRecord::STATUS_PENDING) {
            return null;
        }

        $expiresAt = $this->immutableDate($current->expires_at);

        if (! $expiresAt instanceof DateTimeImmutable || $expiresAt > $now) {
            return null;
        }

        return $this->approvals->expirePending($tokenHash, $now);
    }

    private function ttlMinutes(): int
    {
        return $this->tokenTtlMinutes >= 1 ? $this->tokenTtlMinutes : self::DEFAULT_TTL_MINUTES;
    }

    private function now(): DateTimeImmutable
    {
        if (is_callable($this->clock)) {
            $now = ($this->clock)();

            $immutable = $this->immutableDate($now);

            if ($immutable instanceof DateTimeImmutable) {
                return $immutable;
            }

            throw new InvalidArgumentException('Approval token clock must return a DateTimeInterface or date-time string.');
        }

        return new DateTimeImmutable;
    }

    private function immutableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return null;
    }

    private function generatePlainTextToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
