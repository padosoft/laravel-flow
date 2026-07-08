<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\NodeRegistry;

/**
 * Semantic graph validation against the node catalog: every node type
 * registered, every wire lands on real ports with compatible types, and
 * every required input is fed by a wire or a config literal. Studio and
 * the definition repository run this before persisting/publishing.
 *
 * @api
 */
final class GraphValidator
{
    public function __construct(private readonly NodeRegistry $registry) {}

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

            // An input port accepts exactly one incoming wire: fan-in would
            // give the executor ambiguous last-write-wins semantics. Merging
            // multiple sources requires an explicit merge node (Macro C).
            if (isset($wiredInputs[$wire->targetNodeId][$wire->targetPortKey])) {
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

                if (! $port->type->validates($value)) {
                    $violations[] = "Config value for input [{$port->key}] on node [{$node->id}] must be of type [{$port->type->value}], got [".get_debug_type($value).'].';
                }
            }
        }

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }
    }
}
