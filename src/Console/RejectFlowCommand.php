<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;

/**
 * @internal
 */
final class RejectFlowCommand extends ApprovalDecisionCommand
{
    /**
     * @var string
     */
    protected $signature = 'flow:reject
        {token : Approval token to reject a paused run}
        {--payload= : JSON rejection payload}
        {--actor= : JSON decision actor metadata}';

    /**
     * @var string
     */
    protected $description = 'Reject a paused Laravel Flow run using a one-time token.';

    public function handle(): int
    {
        return $this->handleDecision(
            fn (FlowEngine $flow, string $token, array $payload, array $actor): FlowRun => $flow->reject($token, $payload, $actor),
        );
    }

    protected function resultVerb(): string
    {
        return 'Rejected';
    }
}
