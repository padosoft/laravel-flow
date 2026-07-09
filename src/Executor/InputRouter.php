<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\NodeInputValidator;

/**
 * Resolves a node's validated input map from upstream node outputs (via graph
 * connections) and config literals, then validates it against the node's port
 * contract. A `multiple` (fan-in) port coalesces every incoming wire into an
 * ordered list; a normal port takes its single wired value, falling back to a
 * `config` literal only when it has no incoming wire (a wire always wins over
 * config). Validation failure is returned as an invalid {@see RoutedInputs}
 * (never thrown) so the executor maps it to `invalid_input` without running
 * the handler.
 *
 * Ordering of a `multiple` port's coalesced list follows the order of
 * `$connectionsIntoNode` as supplied — the caller passes them in the source
 * nodes' topological order so the result is deterministic.
 *
 * @api
 */
final class InputRouter
{
    public function __construct(
        private readonly NodeInputValidator $validator = new NodeInputValidator,
    ) {}

    /**
     * @param  list<Connection>  $connectionsIntoNode  wires whose target is $node (any port)
     * @param  array<string, array<string, mixed>>  $upstreamOutputs  sourceNodeId => (outputPortKey => value)
     */
    public function route(
        NodeDefinition $definition,
        GraphNode $node,
        array $connectionsIntoNode,
        array $upstreamOutputs,
    ): RoutedInputs {
        $raw = [];

        // Group incoming wires by target port once (avoids O(ports × wires)).
        /** @var array<string, list<Connection>> $wiresByPort */
        $wiresByPort = [];
        foreach ($connectionsIntoNode as $wire) {
            $wiresByPort[$wire->targetPortKey][] = $wire;
        }

        foreach ($definition->inputs as $port) {
            $wires = $wiresByPort[$port->key] ?? [];

            // Config satisfies a port ONLY when it has no incoming wire at all.
            // A wired port whose upstream produced no value stays absent (the
            // validator then decides required-ness) — config must never mask a
            // missing upstream output. A wire always wins over config.
            $hasWires = $wires !== [];

            if ($port->multiple) {
                if (! $hasWires && array_key_exists($port->key, $node->config)) {
                    $raw[$port->key] = $node->config[$port->key];

                    continue;
                }

                $items = [];
                foreach ($wires as $wire) {
                    if ($this->hasUpstreamValue($upstreamOutputs, $wire)) {
                        $items[] = $upstreamOutputs[$wire->sourceNodeId][$wire->sourcePortKey];
                    }
                }

                $raw[$port->key] = $items;

                continue;
            }

            $wiredValue = null;
            $hasWiredValue = false;
            foreach ($wires as $wire) {
                if ($this->hasUpstreamValue($upstreamOutputs, $wire)) {
                    $wiredValue = $upstreamOutputs[$wire->sourceNodeId][$wire->sourcePortKey];
                    $hasWiredValue = true;
                }
            }

            if ($hasWiredValue) {
                $raw[$port->key] = $wiredValue;

                continue;
            }

            if (! $hasWires && array_key_exists($port->key, $node->config)) {
                $raw[$port->key] = $node->config[$port->key];
            }
        }

        try {
            $validated = $this->validator->validate($definition, $raw);
        } catch (NodeInputValidationException $violation) {
            return new RoutedInputs([], false, $violation);
        }

        return new RoutedInputs($validated, true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $upstreamOutputs
     */
    private function hasUpstreamValue(array $upstreamOutputs, Connection $wire): bool
    {
        return isset($upstreamOutputs[$wire->sourceNodeId])
            && array_key_exists($wire->sourcePortKey, $upstreamOutputs[$wire->sourceNodeId]);
    }
}
