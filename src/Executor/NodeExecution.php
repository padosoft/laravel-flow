<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use DateTimeImmutable;
use Padosoft\LaravelFlow\Executor\Nodes\ApprovalGateNode;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use Padosoft\LaravelFlow\IssuedApprovalToken;
use Padosoft\LaravelFlow\Node\NodeResult;
use Throwable;

/**
 * Outcome of executing a single node through {@see NodeExecutor}: its terminal
 * state, the output port map (for downstream routing) and any error.
 * `$issuedApprovalToken` is set when this execution paused an
 * {@see ApprovalGateNode} AND a token was actually issued — issuance is
 * best-effort (gated on persistence being enabled, and can itself fail; see
 * NodeExecutor's approval-token try/catch), so a paused ApprovalGateNode does
 * NOT guarantee a non-null value here. Mirrors v1's `FlowRun::$approvalTokens`,
 * surfaced up to {@see GraphRunResult} by the synchronous runner.
 *
 * @internal
 */
final readonly class NodeExecution
{
    /**
     * @param  array<string, mixed>  $outputs
     */
    public function __construct(
        public string $nodeId,
        public NodeState $state,
        public array $outputs,
        public ?Throwable $error = null,
        public ?IssuedApprovalToken $issuedApprovalToken = null,
        /**
         * Output ports a branching node activated, or null when it did not
         * branch (every port live). See {@see NodeResult::branch()}.
         *
         * @var list<string>|null
         */
        public ?array $activePorts = null,
        /**
         * When the node paused on a timer ({@see NodeResult::pausedUntil()}) and
         * stays paused: the time it is due to resume. Null otherwise.
         */
        public ?DateTimeImmutable $resumeAt = null,
    ) {}
}
