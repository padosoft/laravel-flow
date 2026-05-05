<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

/**
 * Aggregate read DTO bundling a run with its persisted children for dashboard
 * detail views. JSON payloads (input, output, businessImpact, audit payload,
 * approval decisionPayload/actor) are returned exactly as stored. The package
 * applies redaction before persistence ONLY when
 * `laravel-flow.persistence.redaction.enabled` is true (the default); when the
 * redactor is disabled by config the dashboard will receive raw stored values,
 * so host apps that cannot guarantee that flag must add their own redaction
 * layer before rendering.
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
