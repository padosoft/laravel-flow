<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard\Authorization;

/**
 * Default deny-by-default binding for {@see DashboardActionAuthorizer}.
 *
 * Returns false from every authorization method so the dashboard cannot
 * be accidentally exposed in production without an explicit policy. Host
 * applications MUST replace this binding with their own implementation
 * (typically calling Laravel's Gate/Policy facades or a custom RBAC).
 *
 * For local development against a single trusted operator, host apps can
 * opt into {@see AllowAllAuthorizer} by binding it explicitly:
 *
 *     $this->app->bind(DashboardActionAuthorizer::class, AllowAllAuthorizer::class);
 *
 * @api
 */
final class DenyAllAuthorizer implements DashboardActionAuthorizer
{
    public function canViewRuns(?array $actor): bool
    {
        return false;
    }

    public function canViewRunDetail(string $runId, ?array $actor): bool
    {
        return false;
    }

    public function canReplayRun(string $runId, ?array $actor): bool
    {
        return false;
    }

    public function canApproveByToken(string $tokenHash, ?array $actor): bool
    {
        return false;
    }

    public function canRejectByToken(string $tokenHash, ?array $actor): bool
    {
        return false;
    }

    public function canViewKpis(?array $actor): bool
    {
        return false;
    }
}
