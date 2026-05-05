<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard;

/**
 * Aggregate KPI counters for the dashboard landing view.
 */
final readonly class Kpis
{
    public function __construct(
        public int $totalRuns,
        public int $runningRuns,
        public int $pausedRuns,
        public int $failedRuns,
        public int $compensatedRuns,
        public int $pendingApprovals,
        public int $webhookOutboxPending,
        public int $webhookOutboxFailed,
    ) {}
}
