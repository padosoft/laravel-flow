<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Dashboard;

use Padosoft\LaravelFlow\Dashboard\Authorization\AllowAllAuthorizer;
use PHPUnit\Framework\TestCase;

final class AllowAllAuthorizerTest extends TestCase
{
    public function test_allow_all_returns_true_for_every_action_regardless_of_actor(): void
    {
        $authorizer = new AllowAllAuthorizer;

        $this->assertTrue($authorizer->canViewRuns(null));
        $this->assertTrue($authorizer->canViewRunDetail('any-run', null));
        $this->assertTrue($authorizer->canReplayRun('any-run', ['user_id' => 7]));
        $this->assertTrue($authorizer->canApproveByToken('hash', null));
        $this->assertTrue($authorizer->canRejectByToken('hash', ['role' => 'manager']));
        $this->assertTrue($authorizer->canViewKpis(null));
    }
}
