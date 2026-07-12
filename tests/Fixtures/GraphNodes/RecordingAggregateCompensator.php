<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Aggregate-compensator fixture for the graph saga: appends '@aggregate' to
 * {@see CompensatableRecordingNode::$log} (the shared order log) so tests can
 * assert it runs LAST, and captures the node-outputs map it received.
 */
final class RecordingAggregateCompensator implements FlowCompensator
{
    /** @var array<string, mixed>|null the FlowStepResult output it received */
    public static ?array $received = null;

    public static function reset(): void
    {
        self::$received = null;
    }

    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        CompensatableRecordingNode::$log[] = '@aggregate';
        self::$received = $stepResult->output;
    }
}
