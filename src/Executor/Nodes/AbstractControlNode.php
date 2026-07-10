<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor\Nodes;

use Padosoft\LaravelFlow\Executor\ChildFlowRunner;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use RuntimeException;
use Throwable;

/**
 * Shared execution for the fan-out / sub-flow control nodes. It loads the
 * configured child flow and, per {@see NodeContext::$queued}:
 *
 *  - SYNC: runs each child inline via {@see ChildFlowRunner::runInline()},
 *    honoring `maxConcurrency` as a sequential BATCH size (children within a
 *    batch execute one at a time — the synchronous runner cannot parallelize),
 *    aggregates the per-child outputs into an ordered `results` list, and
 *    returns success (or failure if any child failed).
 *  - QUEUED: spawns each child as its own queued run, records it in the
 *    child ledger, and suspends this node ({@see NodeResult::paused()}); the
 *    join coordinator resumes it once every child terminates — and there
 *    `maxConcurrency` is enforced as REAL concurrency by the queue.
 *
 * Subclasses supply the ordered per-child input payloads via {@see childInputs()}.
 *
 * @internal
 */
abstract class AbstractControlNode implements FlowNodeHandler
{
    public function __construct(
        protected readonly ChildFlowRunner $runner,
    ) {}

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $flow = $context->inputs['flow'] ?? null;

            if (! is_string($flow) || $flow === '') {
                return NodeResult::failed(new RuntimeException('A control node requires a non-empty "flow" config value.'));
            }

            $version = isset($context->inputs['version']) && is_numeric($context->inputs['version'])
                ? (int) $context->inputs['version']
                : null;

            $graph = $this->runner->loadGraph($flow, $version);
            $childInputs = $this->childInputs($context);

            if ($context->queued) {
                foreach ($childInputs as $index => $childInput) {
                    $this->runner->spawn($context->flowRunId, $context->nodeId, $graph, $childInput, $index);
                }

                return NodeResult::paused();
            }

            return $this->runInlineBatches($context, $graph, $childInputs);
        } catch (Throwable $e) {
            return NodeResult::failed($e);
        }
    }

    /**
     * The ordered per-child input payloads. Default: one child per `items`
     * entry (fan-out); {@see SubFlowNode} overrides to a single child.
     *
     * @return list<array<string, mixed>>
     */
    protected function childInputs(NodeContext $context): array
    {
        $items = $context->inputs['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $inputs = [];

        foreach (array_values($items) as $item) {
            $inputs[] = is_array($item) ? $item : ['value' => $item];
        }

        return $inputs;
    }

    protected function maxConcurrency(NodeContext $context): int
    {
        $max = $context->inputs['maxConcurrency'] ?? 1;

        return is_numeric($max) && (int) $max >= 1 ? (int) $max : 1;
    }

    /**
     * @param  list<array<string, mixed>>  $childInputs
     */
    private function runInlineBatches(NodeContext $context, GraphDefinition $graph, array $childInputs): NodeResult
    {
        /** @var array<int, mixed> $outputs */
        $outputs = [];
        $anyFailed = false;

        foreach (array_chunk($childInputs, $this->maxConcurrency($context), true) as $batch) {
            foreach ($batch as $index => $childInput) {
                $outcome = $this->runner->runInline($context->flowRunId, $context->nodeId, $graph, $childInput, $index);
                $outputs[$index] = $outcome['output'];
                $anyFailed = $anyFailed || ! $outcome['succeeded'];
            }
        }

        if ($anyFailed) {
            return NodeResult::failed(new RuntimeException('A child run of the fan-out failed.'));
        }

        ksort($outputs);

        return NodeResult::success(['results' => array_values($outputs)]);
    }
}
