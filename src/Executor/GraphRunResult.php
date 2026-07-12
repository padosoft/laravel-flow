<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Executor\Nodes\ApprovalGateNode;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\Executor\State\RunState;
use Padosoft\LaravelFlow\IssuedApprovalToken;

/**
 * Result of a whole {@see GraphRunner::run()}: the run id, the rolled-up run
 * state, each node's terminal state, each succeeded node's output port map, and
 * any per-node error messages. `$approvalTokens` mirrors v1's
 * `FlowRun::$approvalTokens` — the ONE place a plain (unhashed) approval token
 * is available, keyed by the paused {@see ApprovalGateNode}'s
 * node id; storage is hash-only, so this reference is lost once the response
 * returns unless the caller captures it here.
 *
 * @api
 */
final readonly class GraphRunResult
{
    /**
     * @param  array<string, NodeState>  $nodeStates
     * @param  array<string, array<string, mixed>>  $nodeOutputs
     * @param  array<string, string>  $errors  node id => error message
     * @param  array<string, IssuedApprovalToken>  $approvalTokens  paused approval-gate node id => its issued token
     */
    public function __construct(
        public string $runId,
        public RunState $state,
        public array $nodeStates,
        public array $nodeOutputs,
        public array $errors,
        public array $approvalTokens = [],
    ) {}
}
