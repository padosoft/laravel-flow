<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Models\FlowApprovalRecord;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;
use Padosoft\LaravelFlow\Models\FlowWebhookOutboxRecord;

/**
 * Stable read-only contract for the companion dashboard app.
 *
 * Returns immutable DTOs (not Eloquent records) so the package can
 * evolve persistence internals without breaking the dashboard
 * Composer-path-repo consumer. Method names and DTO shapes are part
 * of the public v1.0 API.
 *
 * @api
 */
final class FlowDashboardReadModel
{
    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    /**
     * @return PaginatedResult<RunSummary>
     */
    public function listRuns(RunFilter $filter, Pagination $pagination): PaginatedResult
    {
        $query = $this->applyRunFilter($this->runQuery(), $filter);

        $total = (clone $query)->count();
        $records = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowRunRecord)) {
                continue;
            }
            $items[] = $this->runSummaryFromRecord($record);
        }

        return new PaginatedResult($items, $total, $pagination->page, $pagination->perPage);
    }

    public function findRun(string $runId): ?RunDetail
    {
        $run = $this->runQuery()->whereKey($runId)->first();

        if (! ($run instanceof FlowRunRecord)) {
            return null;
        }

        $steps = $this->stepsForRun($runId);
        $audit = $this->auditForRun($runId);
        $approvals = $this->approvalsForRun($runId);
        $outbox = $this->webhookOutboxForRun($runId);

        return new RunDetail(
            run: $this->runSummaryFromRecord($run),
            steps: $steps,
            audit: $audit,
            approvals: $approvals,
            webhookOutbox: $outbox,
            input: $this->castNullableArray($run->input),
            output: $this->castNullableArray($run->output),
            businessImpact: $this->castNullableArray($run->business_impact),
        );
    }

    /**
     * @return list<ApprovalSummary>
     */
    public function pendingApprovals(?int $limit = null): array
    {
        $query = $this->approvalQuery()
            ->where('status', FlowApprovalRecord::STATUS_PENDING)
            ->orderBy('created_at');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $records = $query->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowApprovalRecord)) {
                continue;
            }
            $items[] = $this->approvalSummaryFromRecord($record);
        }

        return $items;
    }

    /**
     * @return PaginatedResult<ApprovalSummary>
     */
    public function listApprovals(ApprovalFilter $filter, Pagination $pagination): PaginatedResult
    {
        $query = $this->applyApprovalFilter($this->approvalQuery(), $filter);

        $total = (clone $query)->count();
        $records = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowApprovalRecord)) {
                continue;
            }
            $items[] = $this->approvalSummaryFromRecord($record);
        }

        return new PaginatedResult($items, $total, $pagination->page, $pagination->perPage);
    }

    /**
     * @return PaginatedResult<WebhookOutboxSummary>
     */
    public function listWebhookOutbox(WebhookOutboxFilter $filter, Pagination $pagination): PaginatedResult
    {
        $query = $this->applyWebhookOutboxFilter($this->webhookOutboxQuery(), $filter);

        $total = (clone $query)->count();
        $records = $query
            ->orderByDesc('id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowWebhookOutboxRecord)) {
                continue;
            }
            $items[] = $this->webhookOutboxSummaryFromRecord($record);
        }

        return new PaginatedResult($items, $total, $pagination->page, $pagination->perPage);
    }

    /**
     * @return list<WebhookOutboxSummary>
     */
    public function failedWebhookOutbox(?int $limit = null): array
    {
        return $this->webhookOutboxByStatus(FlowWebhookOutboxRecord::STATUS_FAILED, $limit);
    }

    /**
     * @return list<WebhookOutboxSummary>
     */
    public function pendingWebhookOutbox(?int $limit = null): array
    {
        return $this->webhookOutboxByStatus(FlowWebhookOutboxRecord::STATUS_PENDING, $limit);
    }

    public function kpis(): Kpis
    {
        $runCounts = $this->runQuery()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as running', [FlowRun::STATUS_RUNNING])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as paused', [FlowRun::STATUS_PAUSED])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [FlowRun::STATUS_FAILED])
            ->selectRaw('sum(case when compensated = ? then 1 else 0 end) as compensated', [true])
            ->first();

        $outboxCounts = $this->webhookOutboxQuery()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as pending', [FlowWebhookOutboxRecord::STATUS_PENDING])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [FlowWebhookOutboxRecord::STATUS_FAILED])
            ->first();

        $pendingApprovals = $this->approvalQuery()->where('status', FlowApprovalRecord::STATUS_PENDING)->count();

        return new Kpis(
            totalRuns: $this->intAttr($runCounts, 'total'),
            runningRuns: $this->intAttr($runCounts, 'running'),
            pausedRuns: $this->intAttr($runCounts, 'paused'),
            failedRuns: $this->intAttr($runCounts, 'failed'),
            compensatedRuns: $this->intAttr($runCounts, 'compensated'),
            pendingApprovals: $pendingApprovals,
            webhookOutboxPending: $this->intAttr($outboxCounts, 'pending'),
            webhookOutboxFailed: $this->intAttr($outboxCounts, 'failed'),
        );
    }

    private function intAttr(mixed $row, string $key): int
    {
        if ($row === null) {
            return 0;
        }

        if (is_object($row)) {
            $value = $row->{$key} ?? null;
        } elseif (is_array($row)) {
            $value = $row[$key] ?? null;
        } else {
            $value = null;
        }

        if ($value === null) {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @return list<WebhookOutboxSummary>
     */
    private function webhookOutboxByStatus(string $status, ?int $limit): array
    {
        $query = $this->webhookOutboxQuery()
            ->where('status', $status)
            ->orderByDesc('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $records = $query->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowWebhookOutboxRecord)) {
                continue;
            }
            $items[] = $this->webhookOutboxSummaryFromRecord($record);
        }

        return $items;
    }

    /**
     * @param  Builder<FlowRunRecord>  $query
     * @return Builder<FlowRunRecord>
     */
    private function applyRunFilter(Builder $query, RunFilter $filter): Builder
    {
        if ($filter->definitionName !== null) {
            $query->where('definition_name', $filter->definitionName);
        }

        if ($filter->status !== null) {
            $query->where('status', $filter->status);
        }

        if ($filter->correlationId !== null) {
            $query->where('correlation_id', $filter->correlationId);
        }

        if ($filter->idempotencyKey !== null) {
            $query->where('idempotency_key', $filter->idempotencyKey);
        }

        if ($filter->compensated !== null) {
            $query->where('compensated', $filter->compensated);
        }

        if ($filter->startedSince !== null) {
            $query->where('started_at', '>=', $filter->startedSince);
        }

        if ($filter->startedUntil !== null) {
            $query->where('started_at', '<=', $filter->startedUntil);
        }

        return $query;
    }

    /**
     * @param  Builder<FlowApprovalRecord>  $query
     * @return Builder<FlowApprovalRecord>
     */
    private function applyApprovalFilter(Builder $query, ApprovalFilter $filter): Builder
    {
        if ($filter->status !== null) {
            $query->where('status', $filter->status);
        }

        if ($filter->runId !== null) {
            $query->where('run_id', $filter->runId);
        }

        if ($filter->stepName !== null) {
            $query->where('step_name', $filter->stepName);
        }

        if ($filter->createdSince !== null) {
            $query->where('created_at', '>=', $filter->createdSince);
        }

        if ($filter->createdUntil !== null) {
            $query->where('created_at', '<=', $filter->createdUntil);
        }

        return $query;
    }

    /**
     * @param  Builder<FlowWebhookOutboxRecord>  $query
     * @return Builder<FlowWebhookOutboxRecord>
     */
    private function applyWebhookOutboxFilter(Builder $query, WebhookOutboxFilter $filter): Builder
    {
        if ($filter->status !== null) {
            $query->where('status', $filter->status);
        }

        if ($filter->event !== null) {
            $query->where('event', $filter->event);
        }

        if ($filter->runId !== null) {
            $query->where('run_id', $filter->runId);
        }

        if ($filter->approvalId !== null) {
            $query->where('approval_id', $filter->approvalId);
        }

        if ($filter->createdSince !== null) {
            $query->where('created_at', '>=', $filter->createdSince);
        }

        if ($filter->createdUntil !== null) {
            $query->where('created_at', '<=', $filter->createdUntil);
        }

        return $query;
    }

    /**
     * @return list<StepSummary>
     */
    private function stepsForRun(string $runId): array
    {
        $records = $this->stepQuery()
            ->where('run_id', $runId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowStepRecord)) {
                continue;
            }
            $items[] = new StepSummary(
                id: (int) $record->id,
                runId: (string) $record->run_id,
                name: (string) $record->step_name,
                handler: (string) ($record->handler ?? ''),
                sequence: (int) $record->sequence,
                status: (string) $record->status,
                errorClass: $record->error_class,
                errorMessage: $record->error_message,
                durationMs: $record->duration_ms,
                startedAt: $this->immutable($record->started_at),
                finishedAt: $this->immutable($record->finished_at),
            );
        }

        return $items;
    }

    /**
     * @return list<AuditEntry>
     */
    private function auditForRun(string $runId): array
    {
        $records = $this->auditQuery()
            ->where('run_id', $runId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowAuditRecord)) {
                continue;
            }
            // Prefer engine-captured occurred_at; fall back to Eloquent
            // created_at so legacy/manual rows still surface a stable
            // timestamp instead of a fabricated "now". Skip the row entirely
            // if neither timestamp is populated.
            $occurred = $this->immutable($record->occurred_at)
                ?? $this->immutable($record->created_at);

            if ($occurred === null) {
                continue;
            }

            $items[] = new AuditEntry(
                id: (int) $record->id,
                runId: (string) $record->run_id,
                stepName: $record->step_name,
                event: (string) $record->event,
                occurredAt: $occurred,
                payload: $this->castNullableArray($record->payload),
            );
        }

        return $items;
    }

    /**
     * @return list<ApprovalSummary>
     */
    private function approvalsForRun(string $runId): array
    {
        $records = $this->approvalQuery()
            ->where('run_id', $runId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowApprovalRecord)) {
                continue;
            }
            $items[] = $this->approvalSummaryFromRecord($record);
        }

        return $items;
    }

    /**
     * @return list<WebhookOutboxSummary>
     */
    private function webhookOutboxForRun(string $runId): array
    {
        $records = $this->webhookOutboxQuery()
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($records as $record) {
            if (! ($record instanceof FlowWebhookOutboxRecord)) {
                continue;
            }
            $items[] = $this->webhookOutboxSummaryFromRecord($record);
        }

        return $items;
    }

    private function runSummaryFromRecord(FlowRunRecord $record): RunSummary
    {
        return new RunSummary(
            id: (string) $record->id,
            definitionName: (string) $record->definition_name,
            status: (string) $record->status,
            dryRun: (bool) $record->dry_run,
            failedStep: $record->failed_step,
            compensated: (bool) $record->compensated,
            compensationStatus: $record->compensation_status,
            correlationId: $record->correlation_id,
            idempotencyKey: $record->idempotency_key,
            replayedFromRunId: $record->replayed_from_run_id,
            durationMs: $record->duration_ms,
            startedAt: $this->immutable($record->started_at),
            finishedAt: $this->immutable($record->finished_at),
        );
    }

    private function approvalSummaryFromRecord(FlowApprovalRecord $record): ApprovalSummary
    {
        return new ApprovalSummary(
            id: (string) $record->id,
            runId: (string) $record->run_id,
            stepName: (string) $record->step_name,
            status: (string) $record->status,
            issuedAt: $this->immutable($record->created_at),
            expiresAt: $this->immutable($record->expires_at),
            decidedAt: $this->immutable($record->decided_at),
            consumedAt: $this->immutable($record->consumed_at),
            actor: $this->castNullableArray($record->actor),
            decisionPayload: $this->castNullableArray($record->payload),
        );
    }

    private function webhookOutboxSummaryFromRecord(FlowWebhookOutboxRecord $record): WebhookOutboxSummary
    {
        return new WebhookOutboxSummary(
            id: (int) $record->id,
            runId: $record->run_id,
            approvalId: $record->approval_id,
            event: (string) $record->event,
            status: (string) $record->status,
            attempts: (int) $record->attempts,
            maxAttempts: (int) $record->max_attempts,
            availableAt: $this->immutable($record->available_at),
            deliveredAt: $this->immutable($record->delivered_at),
            failedAt: $this->immutable($record->failed_at),
            lastError: $record->last_error,
        );
    }

    /**
     * @return Builder<FlowRunRecord>
     */
    private function runQuery(): Builder
    {
        return (new FlowRunRecord)->setConnection($this->connection)->newQuery();
    }

    /**
     * @return Builder<FlowStepRecord>
     */
    private function stepQuery(): Builder
    {
        return (new FlowStepRecord)->setConnection($this->connection)->newQuery();
    }

    /**
     * @return Builder<FlowAuditRecord>
     */
    private function auditQuery(): Builder
    {
        return (new FlowAuditRecord)->setConnection($this->connection)->newQuery();
    }

    /**
     * @return Builder<FlowApprovalRecord>
     */
    private function approvalQuery(): Builder
    {
        return (new FlowApprovalRecord)->setConnection($this->connection)->newQuery();
    }

    /**
     * @return Builder<FlowWebhookOutboxRecord>
     */
    private function webhookOutboxQuery(): Builder
    {
        return (new FlowWebhookOutboxRecord)->setConnection($this->connection)->newQuery();
    }

    private function immutable(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function castNullableArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        return null;
    }
}
