<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard\Authorization;

use Padosoft\LaravelFlow\Console\ReplayFlowRunCommand;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * Authorization hook bound by host applications to gate dashboard actions.
 *
 * The companion dashboard app must call these methods before invoking the
 * destructive engine APIs ({@see FlowEngine::resume()} and
 * {@see FlowEngine::reject()}) or before triggering replay through the
 * {@see ReplayFlowRunCommand} `flow:replay` Artisan command (which
 * internally uses `FlowEngine::execute()` with `FlowExecutionOptions`
 * carrying `replayedFromRunId`).
 *
 * Each method receives an actor metadata array supplied by the dashboard
 * (typically including operator user id, role, and request ip). The package
 * does not interpret these fields; they are passed through verbatim.
 *
 * Default binding registered by the service provider is
 * {@see DenyAllAuthorizer}, which rejects every action so production
 * deployments cannot accidentally expose the dashboard. Host apps must
 * explicitly bind their own implementation (or {@see AllowAllAuthorizer}
 * for development) before exposing the dashboard.
 */
interface DashboardActionAuthorizer
{
    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canViewRuns(?array $actor): bool;

    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canViewRunDetail(string $runId, ?array $actor): bool;

    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canReplayRun(string $runId, ?array $actor): bool;

    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canApproveByToken(string $tokenHash, ?array $actor): bool;

    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canRejectByToken(string $tokenHash, ?array $actor): bool;

    /**
     * @param  array<string, mixed>|null  $actor
     */
    public function canViewKpis(?array $actor): bool;
}
