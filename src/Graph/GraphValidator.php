<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Provenance\TaintAnalyzer;

/**
 * Semantic graph validation against the node catalog: every node type
 * registered, every wire lands on real ports with compatible types, every
 * required input is fed by a wire or a config literal, and no untrusted
 * data reaches a port that declared it will not accept any.
 *
 * That last check is the reason a graph can be rejected for a *security*
 * reason rather than a structural one. Because a graph's wiring is stored
 * data and nothing rewires it at run time, the set of paths data can take
 * is knowable before the graph runs — so "a model completion can reach
 * this shell command" is a fact about the definition, catchable at publish
 * time rather than discovered in an incident. See {@see TaintAnalyzer}.
 *
 * The internal {@see DefinitionRepository} implementation runs this on
 * {@see DefinitionRepository::publish()} only — a draft may be
 * semantically incomplete (Studio saves work-in-progress graphs), but a
 * published, executable definition must pass validation. Studio (live
 * authoring feedback) and importers may also call this directly ahead of
 * persisting.
 *
 * @api
 */
final class GraphValidator
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly ?TaintAnalyzer $taint = null,
    ) {}

    /**
     * @throws InvalidGraphException
     */
    public function validate(GraphDefinition $graph): void
    {
        $violations = [];
        $definitions = [];

        foreach ($graph->nodes as $node) {
            try {
                $definitions[$node->id] = $this->registry->get($node->type);
            } catch (UnknownNodeTypeException) {
                $violations[] = "Unknown node type [{$node->type}] on node [{$node->id}].";
            }
        }

        $wiredInputs = [];

        foreach ($graph->connections as $wire) {
            $source = $definitions[$wire->sourceNodeId] ?? null;
            $target = $definitions[$wire->targetNodeId] ?? null;

            $out = $source?->output($wire->sourcePortKey);
            $in = $target?->input($wire->targetPortKey);

            if ($source !== null && $out === null) {
                $violations[] = "Connection [{$wire->identity()}] references unknown output port [{$wire->sourcePortKey}] on [{$wire->sourceNodeId}].";
            }

            if ($target !== null && $in === null) {
                $violations[] = "Connection [{$wire->identity()}] references unknown input port [{$wire->targetPortKey}] on [{$wire->targetNodeId}].";
            }

            if ($out !== null && $in !== null && ! $in->type->accepts($out->type)) {
                $violations[] = "Connection [{$wire->identity()}]: output type [{$out->type->value}] cannot feed input type [{$in->type->value}].";
            }

            // A normal input port accepts exactly one incoming wire: fan-in
            // would give the executor ambiguous last-write-wins semantics. A
            // `multiple` (fan-in) port deliberately coalesces every wire into
            // an ordered list, so N sources are allowed there.
            if ($in !== null && ! $in->multiple && isset($wiredInputs[$wire->targetNodeId][$wire->targetPortKey])) {
                $violations[] = "Input port [{$wire->targetPortKey}] on node [{$wire->targetNodeId}] is wired from multiple sources.";
            }

            $wiredInputs[$wire->targetNodeId][$wire->targetPortKey] = true;
        }

        foreach ($graph->nodes as $node) {
            $definition = $definitions[$node->id] ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($definition->inputs as $port) {
                $hasConfig = array_key_exists($port->key, $node->config);
                $satisfied = isset($wiredInputs[$node->id][$port->key]) || $hasConfig;

                if ($port->required && ! $satisfied) {
                    $violations[] = "Required input [{$port->key}] on node [{$node->id}] is unwired and has no config value.";
                }

                if (! $hasConfig) {
                    continue;
                }

                // Config literals are validated here so a graph that cannot
                // pass runtime input validation is rejected at publish time.
                $value = $node->config[$port->key];

                if ($value === null) {
                    if ($port->required) {
                        $violations[] = "Config value for required input [{$port->key}] on node [{$node->id}] must not be null.";
                    }

                    continue;
                }

                // A multiple (fan-in) port satisfied by a config literal must
                // pass the same list/non-empty/per-item rules the runtime input
                // validator applies, so an always-`invalid_input` graph cannot
                // be published/imported.
                if ($port->multiple) {
                    if (! is_array($value) || ! array_is_list($value)) {
                        $violations[] = "Config value for multiple input [{$port->key}] on node [{$node->id}] must be a list, got [".get_debug_type($value).'].';

                        continue;
                    }

                    if ($port->required && $value === []) {
                        $violations[] = "Config value for required multiple input [{$port->key}] on node [{$node->id}] must not be an empty list.";

                        continue;
                    }

                    foreach ($value as $index => $item) {
                        if ($item === null) {
                            $violations[] = "Config value for input [{$port->key}][{$index}] on node [{$node->id}] must not be null.";

                            continue;
                        }

                        if (! $port->type->validates($item)) {
                            $violations[] = "Config value for input [{$port->key}][{$index}] on node [{$node->id}] must be of type [{$port->type->value}], got [".get_debug_type($item).'].';
                        }
                    }

                    continue;
                }

                if (! $port->type->validates($value)) {
                    $violations[] = "Config value for input [{$port->key}] on node [{$node->id}] must be of type [{$port->type->value}], got [".get_debug_type($value).'].';
                }
            }
        }

        // Taint runs LAST and only on an otherwise-valid graph. The
        // analysis reads port definitions and wires, so on a graph with
        // unknown node types or dangling wires it would report confident
        // nonsense about a structure that does not hold — and burying the
        // real error under a speculative one is how a security message
        // gets ignored.
        if ($violations === []) {
            foreach ($this->taintAnalyzer()->violations($graph) as $violation) {
                $violations[] = $violation->message();
            }
        }

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }
    }

    private function taintAnalyzer(): TaintAnalyzer
    {
        return $this->taint ?? new TaintAnalyzer($this->registry);
    }
}
