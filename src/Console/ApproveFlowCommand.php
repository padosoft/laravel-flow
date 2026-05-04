<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;

final class ApproveFlowCommand extends ApprovalDecisionCommand
{
    /**
     * @var string
     */
    protected $signature = 'flow:approve
        {token : Approval token to resume a paused run}
        {--payload= : JSON approval payload}
        {--actor= : JSON approval actor metadata}';

    /**
     * @var string
     */
    protected $description = 'Approve a paused Laravel Flow run using a one-time token.';

    public function handle(): int
    {
        return $this->handleDecision(
            fn (FlowEngine $flow, string $token, array $payload, array $actor): FlowRun => $flow->resume($token, $payload, $actor),
        );
    }

    protected function resultVerb(): string
    {
        return 'Approved';
    }
}
