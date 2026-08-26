<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

use JsonException;
use Padosoft\LaravelFlow\Executor\InputRouter;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Node\NodeRegistry;

/**
 * Checks what a bundle can actually prove.
 *
 * Four questions, in the order an investigator asks them:
 *
 *  1. **Has this file been edited since export?** The content digest is
 *     recomputed from the body and compared with the one the file carries.
 *  2. **Is the embedded graph the graph that was signed?** The definition
 *     checksum is recomputed from the snapshot the run itself stored — not from
 *     whatever is registered under that name today, which anyone with database
 *     access could have changed since.
 *  3. **Is the sequence coherent?** Duplicate or out-of-order sequence numbers
 *     mean the record was assembled, not observed.
 *  4. **Do the recorded inputs follow from the recorded outputs?** This is the
 *     deterministic replay: node bodies are the non-deterministic half of a flow
 *     and their outputs are recorded, but the ROUTING between them is pure. Re-run
 *     the router over the recorded upstream outputs and the inputs it derives must
 *     be the inputs that were recorded. When they are not, either the graph in the
 *     bundle is not the graph that ran, or the node rows were edited — and the
 *     finding names the node where the two stories first stop agreeing.
 *
 * The verifier NEVER runs a node handler. It reads; it does not act. That is
 * what makes it safe to point at a production incident.
 *
 * @api
 */
final class ForensicVerifier
{
    public const CHECK_DIGEST = 'content_digest';

    public const CHECK_DEFINITION = 'definition_checksum';

    public const CHECK_SEQUENCE = 'node_sequence';

    public const CHECK_ROUTING = 'input_routing';

    public function __construct(
        private readonly NodeRegistry $nodes,
        private readonly GraphSerializer $serializer = new GraphSerializer,
        private readonly InputRouter $router = new InputRouter,
    ) {}

    public function verify(ForensicBundle $bundle, ?string $expectedDigest = null): ForensicReport
    {
        $findings = [
            $this->checkDigest($bundle, $expectedDigest),
            ...$this->checkSequence($bundle),
        ];

        $graph = $this->graphOf($bundle, $findings);

        if ($graph !== null) {
            $findings = [...$findings, ...$this->checkRouting($bundle, $graph)];
        }

        return new ForensicReport(array_values($findings));
    }

    private function checkDigest(ForensicBundle $bundle, ?string $expectedDigest): ForensicFinding
    {
        try {
            $actual = $bundle->digest();
        } catch (JsonException $e) {
            return ForensicFinding::failed(self::CHECK_DIGEST, 'The bundle cannot be canonicalized: '.$e->getMessage());
        }

        if ($expectedDigest === null) {
            // No claim to check against. Recomputing a digest and comparing it
            // with itself would always pass and prove nothing.
            return ForensicFinding::unverifiable(self::CHECK_DIGEST, "No expected digest supplied; the bundle's own digest is {$actual}.");
        }

        return hash_equals($expectedDigest, $actual)
            ? ForensicFinding::ok(self::CHECK_DIGEST, 'The bundle matches the digest it carries.')
            : ForensicFinding::failed(self::CHECK_DIGEST, "Digest mismatch: the document claims {$expectedDigest} but its content is {$actual}. It has been altered since export.");
    }

    /**
     * @param  list<ForensicFinding>  $findings
     */
    private function graphOf(ForensicBundle $bundle, array &$findings): ?GraphDefinition
    {
        $payload = $bundle->definition['graph'] ?? null;

        if (! is_array($payload)) {
            $findings[] = ForensicFinding::unverifiable(self::CHECK_DEFINITION, 'The bundle carries no graph snapshot; nothing downstream can be checked against it.');

            return null;
        }

        try {
            $graph = $this->serializer->fromArray($payload);
        } catch (InvalidGraphException $e) {
            $findings[] = ForensicFinding::failed(self::CHECK_DEFINITION, 'The embedded graph does not parse: '.$e->getMessage());

            return null;
        }

        $claimed = $bundle->definition['checksum'] ?? null;

        if (! is_string($claimed) || $claimed === '') {
            $findings[] = ForensicFinding::unverifiable(self::CHECK_DEFINITION, 'The run recorded no definition checksum to compare the snapshot against.');

            return $graph;
        }

        $actual = $this->serializer->checksum($graph);

        $findings[] = hash_equals($claimed, $actual)
            ? ForensicFinding::ok(self::CHECK_DEFINITION, 'The embedded graph matches the checksum the run recorded.')
            : ForensicFinding::failed(self::CHECK_DEFINITION, "The embedded graph does not match the recorded checksum ({$claimed} vs {$actual}): the bundle's graph is not the graph that ran.");

        return $graph;
    }

    /**
     * @return list<ForensicFinding>
     */
    private function checkSequence(ForensicBundle $bundle): array
    {
        $seen = [];
        $previous = null;

        foreach ($bundle->nodes as $node) {
            $sequence = $node['sequence'] ?? null;
            $nodeId = is_string($node['node_id'] ?? null) ? $node['node_id'] : null;

            if (! is_int($sequence)) {
                // A node with no sequence is normal for some engines; it just
                // cannot take part in an ordering check.
                continue;
            }

            if (isset($seen[$sequence])) {
                return [ForensicFinding::failed(self::CHECK_SEQUENCE, "Sequence {$sequence} appears twice: the record was assembled, not observed.", $nodeId)];
            }

            if ($previous !== null && $sequence < $previous) {
                return [ForensicFinding::failed(self::CHECK_SEQUENCE, "Sequence {$sequence} follows {$previous}: the nodes are not in execution order.", $nodeId)];
            }

            $seen[$sequence] = true;
            $previous = $sequence;
        }

        return $seen === []
            ? [ForensicFinding::unverifiable(self::CHECK_SEQUENCE, 'No node carries a sequence number; execution order cannot be checked.')]
            : [ForensicFinding::ok(self::CHECK_SEQUENCE, sprintf('%d node(s) in a coherent execution order.', count($seen)))];
    }

    /**
     * The deterministic replay.
     *
     * @return list<ForensicFinding>
     */
    private function checkRouting(ForensicBundle $bundle, GraphDefinition $graph): array
    {
        if ($bundle->redacted) {
            // Redaction rewrites payload values, so a mismatch here would say
            // nothing about the record and everything about the mask. Refusing
            // to check is the honest answer; pretending to check is not.
            return [ForensicFinding::unverifiable(self::CHECK_ROUTING, 'The bundle is redacted: derived inputs cannot be compared against masked values. Export with --raw inside a controlled environment to verify routing.')];
        }

        $findings = [];
        $outputs = [];
        $checked = 0;

        foreach ($bundle->nodes as $row) {
            $nodeId = is_string($row['node_id'] ?? null) ? $row['node_id'] : null;
            $node = $nodeId !== null ? $graph->node($nodeId) : null;

            if ($nodeId === null || $node === null) {
                $findings[] = ForensicFinding::failed(self::CHECK_ROUTING, 'A recorded node is absent from the embedded graph: the record and the graph disagree about what ran.', $nodeId);

                continue;
            }

            $recordedInputs = is_array($row['inputs'] ?? null) ? $row['inputs'] : null;

            if ($recordedInputs !== null && $this->nodes->has($node->type)) {
                $wires = array_values(array_filter(
                    $graph->connections,
                    static fn (Connection $c): bool => $c->targetNodeId === $nodeId,
                ));

                $routed = $this->router->route($this->nodes->get($node->type), $node, $wires, $outputs);

                if ($routed->valid) {
                    $checked++;

                    if (! $this->same($routed->inputs, $recordedInputs)) {
                        $findings[] = ForensicFinding::failed(
                            self::CHECK_ROUTING,
                            'The inputs recorded for this node are not the inputs the graph derives from the recorded upstream outputs: this is where the record and the graph stop agreeing.',
                            $nodeId,
                        );
                    }
                } else {
                    $findings[] = ForensicFinding::unverifiable(self::CHECK_ROUTING, 'Routing could not be re-derived (upstream outputs missing or incomplete in the record).', $nodeId);
                }
            } elseif ($recordedInputs !== null) {
                $findings[] = ForensicFinding::unverifiable(self::CHECK_ROUTING, "Node type [{$node->type}] is not registered here, so its port contract is unknown.", $nodeId);
            }

            if (is_array($row['outputs'] ?? null)) {
                $outputs[$nodeId] = $row['outputs'];
            }
        }

        if ($checked > 0) {
            $findings[] = ForensicFinding::ok(self::CHECK_ROUTING, sprintf('%d node input map(s) re-derived from the recorded outputs.', $checked));
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function same(array $a, array $b): bool
    {
        try {
            return ForensicCanonicalJson::encode($a) === ForensicCanonicalJson::encode($b);
        } catch (JsonException) {
            // Un-encodable values cannot be compared: report a difference rather
            // than a match, because "I could not tell" must never read as "same".
            return false;
        }
    }
}
