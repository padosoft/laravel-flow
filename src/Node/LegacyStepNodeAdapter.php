<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use InvalidArgumentException;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Runs a v1 {@see FlowStepHandler} as a v2 graph node. The step's whole
 * input array travels on the single `input` Json port and its output on
 * the `output` Json port; result semantics map 1:1.
 *
 * @api
 */
final class LegacyStepNodeAdapter implements FlowNodeHandler
{
    public function __construct(private readonly FlowStepHandler $step) {}

    /**
     * @param  class-string<FlowStepHandler>  $stepHandlerClass
     */
    public static function definitionFor(string $nodeType, string $stepHandlerClass): NodeDefinition
    {
        return new NodeDefinition(
            type: $nodeType,
            name: self::classBasename($stepHandlerClass),
            category: 'legacy',
            icon: null,
            description: 'v1 FlowStepHandler adapter for '.$stepHandlerClass,
            inputs: [new PortDefinition('input', PortType::Json)],
            outputs: [new PortDefinition('output', PortType::Json)],
            handlerClass: $stepHandlerClass,
        );
    }

    private static function classBasename(string $class): string
    {
        $pos = strrpos($class, '\\');

        // Global-namespace classes have no separator: use the name as-is.
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    public function execute(NodeContext $context): NodeResult
    {
        $input = $context->inputs['input'] ?? [];

        if (! is_array($input)) {
            return NodeResult::failed(new InvalidArgumentException('Legacy input port must carry an array payload.'));
        }

        $result = $this->step->execute(new FlowContext(
            flowRunId: $context->flowRunId,
            definitionName: $context->definitionName,
            input: $input,
            stepOutputs: [],
            dryRun: $context->dryRun,
        ));

        return $this->mapResult($result);
    }

    private function mapResult(FlowStepResult $result): NodeResult
    {
        if ($result->dryRunSkipped) {
            return NodeResult::dryRunSkipped();
        }

        if ($result->paused) {
            return NodeResult::paused(['output' => $result->output], $result->businessImpact);
        }

        if (! $result->success) {
            return NodeResult::failed($result->error ?? new RuntimeException('Legacy step failed without error detail.'));
        }

        return NodeResult::success(['output' => $result->output], $result->businessImpact);
    }
}
