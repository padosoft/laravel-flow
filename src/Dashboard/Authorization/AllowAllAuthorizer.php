<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard\Authorization;

/**
 * Permissive opt-in binding for {@see DashboardActionAuthorizer}.
 *
 * NOT the default. The package registers {@see DenyAllAuthorizer} by
 * default so production environments cannot accidentally expose the
 * dashboard. Bind this class explicitly only when you need a no-auth
 * local-development setup:
 *
 *     $this->app->bind(DashboardActionAuthorizer::class, AllowAllAuthorizer::class);
 *
 * Production deployments MUST replace the binding with a host-app
 * implementation that enforces the actual RBAC.
 *
 * @api
 */
final class AllowAllAuthorizer implements DashboardActionAuthorizer
{
    public function canViewRuns(?array $actor): bool
    {
        return true;
    }

    public function canViewRunDetail(string $runId, ?array $actor): bool
    {
        return true;
    }

    public function canReplayRun(string $runId, ?array $actor): bool
    {
        return true;
    }

    public function canApproveByToken(string $tokenHash, ?array $actor): bool
    {
        return true;
    }

    public function canRejectByToken(string $tokenHash, ?array $actor): bool
    {
        return true;
    }

    public function canViewKpis(?array $actor): bool
    {
        return true;
    }
}
