<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Provenance;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\PortProvenance;

/**
 * Static taint analysis over a graph: which ports carry untrusted data,
 * along which path, and which wires hand it to a port that refuses it.
 *
 * **Why static analysis is the whole answer here, not a first line of it.**
 * A flow graph's wiring is part of the stored definition. Connections are
 * authored and versioned; nothing rewires a published graph at run time.
 * So the set of paths data can take is known before the graph ever runs,
 * and this pass is *complete* for the property it checks — there is no
 * dynamic case it can miss and a runtime check would have to catch. That
 * is a genuinely unusual position to be in (taint analysis in a general
 * language is undecidable in the limit), and it is worth naming: it comes
 * from the graph being data rather than code.
 *
 * What the pass does NOT claim: it says nothing about whether a handler
 * *internally* fetches something untrusted without declaring it. A node
 * that quietly calls an HTTP API and returns the body on a port declared
 * {@see PortProvenance::Derived} lies to this analysis, and no amount of
 * graph-level reasoning would catch it. That honesty gap is the reason
 * {@see PortProvenance::Trusted} is written as an accountable claim rather
 * than inferred.
 *
 * Propagation is conservative in the one direction that matters: any
 * untrusted input taints every {@see PortProvenance::Derived} output of
 * that node. A node cannot launder data by passing it through — only by
 * declaring a {@see PortProvenance::Trusted} port and owning that claim.
 *
 * A value supplied by node `config` is trusted: the graph author wrote it
 * into the definition, so it is authored input, not attacker input.
 *
 * @api
 */
final class TaintAnalyzer
{
    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * Compute the taint map for a graph.
     *
     * Propagation walks nodes in topological order, so it depends on that
     * order containing EVERY node. {@see GraphDefinition} guarantees this
     * today — it refuses to construct an empty or cyclic graph, and Kahn's
     * algorithm emits every node of a DAG — and a test pins the guarantee.
     * The check below is not redundancy for its own sake: if that
     * invariant ever broke, the loop would skip nodes and quietly report
     * their untrusted outputs as trusted, which is the single error this
     * class must never make. So a partial order produces NO answer rather
     * than a reassuring one.
     */
    public function analyze(GraphDefinition $graph): TaintMap
    {
        $order = $graph->topologicalOrder();

        if (count($order) !== count($graph->nodes)) {
            return new TaintMap([], []);
        }

        $definitions = $this->definitions($graph);

        /** @var array<string, list<Connection>> $wiresByTarget */
        $wiresByTarget = [];
        foreach ($graph->connections as $wire) {
            $wiresByTarget[$wire->targetNodeId][] = $wire;
        }

        /** @var array<string, array<string, TaintPath>> $inputTaint */
        $inputTaint = [];
        /** @var array<string, array<string, TaintPath>> $outputTaint */
        $outputTaint = [];

        foreach ($order as $nodeId) {
            $definition = $definitions[$nodeId] ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($wiresByTarget[$nodeId] ?? [] as $wire) {
                $upstream = $outputTaint[$wire->sourceNodeId][$wire->sourcePortKey] ?? null;

                if ($upstream === null) {
                    continue;
                }

                // First tainted wire into a port wins. A `multiple` (fan-in)
                // port coalesces several wires into one list, and one
                // untrusted element makes the whole list untrusted — there
                // is no per-element taint, because a handler receiving the
                // list cannot be assumed to keep the elements apart.
                $inputTaint[$nodeId][$wire->targetPortKey] ??= $upstream->then($nodeId, $wire->targetPortKey);
            }

            $nodeInputsTainted = ($inputTaint[$nodeId] ?? []) !== [];

            foreach ($definition->outputs as $port) {
                $path = $this->pathForOutput($nodeId, $port->key, $port->provenance, $nodeInputsTainted, $inputTaint[$nodeId] ?? []);

                if ($path !== null) {
                    $outputTaint[$nodeId][$port->key] = $path;
                }
            }
        }

        return new TaintMap($inputTaint, $outputTaint);
    }

    /**
     * Every wire that lands untrusted data on a port declaring
     * `requiresTrusted`, in a deterministic order so a validator's message
     * list and a test's expectation stay stable.
     *
     * @return list<TaintViolation>
     */
    public function violations(GraphDefinition $graph, ?TaintMap $map = null): array
    {
        $map ??= $this->analyze($graph);
        $definitions = $this->definitions($graph);

        $violations = [];

        foreach ($graph->nodes as $node) {
            $definition = $definitions[$node->id] ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($definition->inputs as $port) {
                if (! $port->requiresTrusted) {
                    continue;
                }

                $path = $map->pathToInput($node->id, $port->key);

                if ($path === null) {
                    continue;
                }

                $violations[] = new TaintViolation($node->id, $port->key, $path);
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, TaintPath>  $portPaths  this node's tainted input ports
     */
    private function pathForOutput(
        string $nodeId,
        string $portKey,
        PortProvenance $provenance,
        bool $anyInputTainted,
        array $portPaths,
    ): ?TaintPath {
        return match ($provenance) {
            // A declared source is untrusted whether or not anything fed it:
            // the taint originates here, so the path starts here.
            PortProvenance::Untrusted => TaintPath::source($nodeId, $portKey),

            // An explicit sanitization claim stops propagation dead. This is
            // the only way taint leaves a graph, and it is deliberately the
            // only way.
            PortProvenance::Trusted => null,

            PortProvenance::Derived => $anyInputTainted
                ? $this->firstPath($portPaths)?->then($nodeId, $portKey)
                : null,
        };
    }

    /**
     * @param  array<string, TaintPath>  $portPaths
     */
    private function firstPath(array $portPaths): ?TaintPath
    {
        foreach ($portPaths as $path) {
            return $path;
        }

        return null;
    }

    /**
     * @return array<string, NodeDefinition>
     */
    private function definitions(GraphDefinition $graph): array
    {
        $definitions = [];

        foreach ($graph->nodes as $node) {
            try {
                $definitions[$node->id] = $this->registry->get($node->type);
            } catch (UnknownNodeTypeException) {
                // Reported by GraphValidator as its own violation; a node we
                // cannot resolve contributes no ports, so it neither creates
                // nor propagates taint here.
            }
        }

        return $definitions;
    }
}
