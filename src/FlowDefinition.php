<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeRegistry;

/**
 * Readonly aggregate describing a registered flow.
 *
 * @phpstan-type FlowSteps list<FlowStep>
 *
 * @api
 */
final class FlowDefinition
{
    /**
     * Reserved node type for every step compiled by {@see self::toGraphDefinition()}.
     *
     * All v1 steps share this single type instead of one type per step:
     * the v2 graph executor (Macro C, C-PR3 in the program master plan)
     * resolves it by building a {@see LegacyStepNodeAdapter}
     * around the container-built class named in `GraphNode::$config['handler']`,
     * not by looking the type up in {@see NodeRegistry}.
     * `legacy.step` is therefore intentionally NEVER registered there.
     */
    public const LEGACY_NODE_TYPE = 'legacy.step';

    /**
     * @param  list<string>  $requiredInputs
     * @param  list<FlowStep>  $steps
     */
    public function __construct(
        public readonly string $name,
        public readonly array $requiredInputs,
        public readonly array $steps,
        public readonly ?string $aggregateCompensatorFqcn = null,
    ) {}

    /**
     * Compiles this v1 linear step chain into an executable v2 {@see GraphDefinition}.
     *
     * Every {@see FlowStep} becomes a {@see GraphNode} of type
     * {@see self::LEGACY_NODE_TYPE} (id = step name), wired
     * `output`->`input` to the next step so the graph is a simple path —
     * trivially acyclic. `legacy.step` nodes are NOT resolvable through
     * `NodeRegistry`/`NodeCatalog`; a compiled graph passes
     * `GraphDefinition`'s STRUCTURAL validation only. Semantic
     * resolution/execution (building a `LegacyStepNodeAdapter` around the
     * container-built `config['handler']` class) arrives with the graph
     * executor in Macro C, per the program master plan. This method is
     * purely additive: it never changes v1's `register()`/`execute()` path.
     *
     * A zero-step definition compiles to zero nodes, which
     * `GraphDefinition`'s constructor already rejects with
     * `InvalidGraphException` ("Graph must contain at least one node.") —
     * the same failure any hand-built empty `GraphDefinition` would hit,
     * so no special-casing is needed here. (In practice `FlowDefinitionBuilder::register()`
     * already refuses to register a zero-step definition; this path only
     * matters for a `FlowDefinition` constructed directly.)
     *
     * @throws InvalidGraphException when this definition has zero steps
     */
    public function toGraphDefinition(): GraphDefinition
    {
        $nodes = [];
        $connections = [];
        $previousStepName = null;

        foreach ($this->steps as $step) {
            $nodes[] = new GraphNode(
                id: $step->name,
                type: self::LEGACY_NODE_TYPE,
                config: [
                    'handler' => $step->handlerFqcn,
                    'supports_dry_run' => $step->supportsDryRun,
                    'compensator' => $step->compensatorFqcn,
                    'approval_gate' => $step->handlerFqcn === ApprovalGate::class,
                ],
            );

            if ($previousStepName !== null) {
                $connections[] = new Connection($previousStepName, 'output', $step->name, 'input');
            }

            $previousStepName = $step->name;
        }

        return new GraphDefinition($nodes, $connections, [
            'required_inputs' => $this->requiredInputs,
            'aggregate_compensator' => $this->aggregateCompensatorFqcn,
            'compiled_from' => 'v1-builder',
        ]);
    }
}
