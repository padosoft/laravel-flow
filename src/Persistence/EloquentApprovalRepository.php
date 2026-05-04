<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use InvalidArgumentException;
use Padosoft\LaravelFlow\Contracts\ApprovalRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;

final class EloquentApprovalRepository implements ApprovalRepository
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function createPending(
        string $id,
        string $runId,
        string $stepName,
        string $tokenHash,
        DateTimeInterface $expiresAt,
        array $payload = [],
    ): FlowApprovalRecord {
        $model = $this->newModel();
        $model->forceFill($this->redact([
            'expires_at' => $expiresAt,
            'id' => $id,
            'payload' => $payload === [] ? null : $payload,
            'run_id' => $runId,
            'status' => FlowApprovalRecord::STATUS_PENDING,
            'step_name' => $stepName,
            'token_hash' => $tokenHash,
        ]))->save();

        return $model->refresh();
    }

    public function findPendingByTokenHash(string $tokenHash): ?FlowApprovalRecord
    {
        return $this->newModel()->newQuery()
            ->where('token_hash', $tokenHash)
            ->where('status', FlowApprovalRecord::STATUS_PENDING)
            ->first();
    }

    public function consumePending(
        string $tokenHash,
        string $status,
        array $actor = [],
        array $payload = [],
        ?DateTimeInterface $decidedAt = null,
    ): ?FlowApprovalRecord {
        if (! in_array($status, [FlowApprovalRecord::STATUS_APPROVED, FlowApprovalRecord::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported approval decision status [%s].', $status));
        }

        $now = $decidedAt ?? $this->newModel()->freshTimestamp();

        return $this->updatePending($tokenHash, [
            'actor' => $actor === [] ? null : $actor,
            'consumed_at' => $now,
            'decided_at' => $now,
            'payload' => $payload === [] ? null : $payload,
            'status' => $status,
        ]);
    }

    public function expirePending(string $tokenHash, DateTimeInterface $decidedAt): ?FlowApprovalRecord
    {
        return $this->updatePending($tokenHash, [
            'decided_at' => $decidedAt,
            'status' => FlowApprovalRecord::STATUS_EXPIRED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updatePending(string $tokenHash, array $attributes): ?FlowApprovalRecord
    {
        $model = $this->newModel();
        $attributes['updated_at'] = $attributes['decided_at'] ?? $model->freshTimestamp();
        $model->forceFill($this->redact($attributes));

        $query = $this->newModel()->newQuery()
            ->where('token_hash', $tokenHash)
            ->where('status', FlowApprovalRecord::STATUS_PENDING);

        if (in_array($attributes['status'] ?? null, [FlowApprovalRecord::STATUS_APPROVED, FlowApprovalRecord::STATUS_REJECTED], true)) {
            $query->where('expires_at', '>', $attributes['decided_at']);
        }

        $updated = $query->update($model->getAttributes());

        if ($updated !== 1) {
            return null;
        }

        return $this->newModel()->newQuery()
            ->where('token_hash', $tokenHash)
            ->first();
    }

    private function newModel(): FlowApprovalRecord
    {
        return (new FlowApprovalRecord)->setConnection($this->connection);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        return PersistencePayloadRedaction::redactFields(
            $this->redactor,
            $attributes,
            PersistencePayloadRedaction::APPROVAL_JSON_FIELDS,
        );
    }
}
