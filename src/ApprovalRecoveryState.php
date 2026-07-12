<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;

/**
 * @internal
 */
final readonly class ApprovalRecoveryState
{
    /**
     * @param  list<FlowStep>  $completedSteps
     * @param  list<FlowStep>  $retryCompletedSteps
     */
    public function __construct(
        public int $approvalIndex,
        public FlowStep $approvalStep,
        public FlowRunNodeRecord $approvalStepRecord,
        public array $completedSteps,
        public FlowContext $context,
        public FlowDefinition $definition,
        public ?string $pausedDownstreamStep,
        public array $retryCompletedSteps,
        public FlowContext $retryContext,
        public int $retrySequence,
        public int $retryStartIndex,
        public FlowRun $run,
        public FlowRunRecord $runRecord,
    ) {}

    public function wouldExecuteHandlers(): bool
    {
        return $this->retryStartIndex < count($this->definition->steps);
    }
}
