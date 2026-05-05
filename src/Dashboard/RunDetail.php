<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

/**
 * Aggregate read DTO bundling a run with its persisted children for dashboard
 * detail views. JSON payloads are returned as already-redacted by persistence.
 */
final readonly class RunDetail
{
    /**
     * @param  list<StepSummary>  $steps
     * @param  list<AuditEntry>  $audit
     * @param  list<ApprovalSummary>  $approvals
     * @param  list<WebhookOutboxSummary>  $webhookOutbox
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>|null  $businessImpact
     */
    public function __construct(
        public RunSummary $run,
        public array $steps,
        public array $audit,
        public array $approvals,
        public array $webhookOutbox,
        public ?array $input,
        public ?array $output,
        public ?array $businessImpact,
    ) {}
}
