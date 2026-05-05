<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Dashboard\Authorization;

/**
 * Default binding for {@see DashboardActionAuthorizer}.
 *
 * Allows every operation. Production deployments MUST override this
 * binding because authorization is the host application's responsibility.
 * Keeping the default permissive avoids forcing every consumer to provide
 * a binding before they can use any read API in development.
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
