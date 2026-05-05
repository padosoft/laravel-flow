<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard\Authorization;

use Padosoft\LaravelFlow\FlowEngine;

/**
 * Authorization hook bound by host applications to gate dashboard actions.
 *
 * The companion dashboard app must call these methods before invoking the
 * destructive engine APIs ({@see FlowEngine::replay()},
 * {@see FlowEngine::resume()},
 * {@see FlowEngine::reject()}).
 *
 * Each method receives an actor metadata array supplied by the dashboard
 * (typically including operator user id, role, and request ip). The package
 * does not interpret these fields; they are passed through verbatim.
 *
 * Default binding registered by the service provider is
 * {@see AllowAllAuthorizer}, which intentionally allows every operation
 * because authorization belongs to the host app. Production deployments
 * MUST override the binding before exposing the dashboard.
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
