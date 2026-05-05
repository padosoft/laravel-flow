<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Dashboard;

use Padosoft\LaravelFlow\Dashboard\Authorization\DenyAllAuthorizer;
use PHPUnit\Framework\TestCase;

final class DenyAllAuthorizerTest extends TestCase
{
    public function test_deny_all_returns_false_for_every_action_regardless_of_actor(): void
    {
        $authorizer = new DenyAllAuthorizer;

        $this->assertFalse($authorizer->canViewRuns(null));
        $this->assertFalse($authorizer->canViewRunDetail('any-run', null));
        $this->assertFalse($authorizer->canReplayRun('any-run', ['user_id' => 7]));
        $this->assertFalse($authorizer->canApproveByToken('hash', null));
        $this->assertFalse($authorizer->canRejectByToken('hash', ['role' => 'manager']));
        $this->assertFalse($authorizer->canViewKpis(null));
    }
}
