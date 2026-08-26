<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Provenance;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Provenance\TaintAnalyzer;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\FanInNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\JsonEmitNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\SanitizingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\TrustedSinkNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\UntrustedSourceNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

/**
 * The property under test is one sentence: attacker-chosen text must not be
 * able to reach a port that was declared to refuse it, and the analysis
 * must be able to say WHY when it does.
 *
 * The tests are written as the attack, not as the API. Each one is a graph
 * someone could plausibly draw in a visual editor without noticing what
 * they had just wired together.
 */
final class TaintAnalyzerTest extends TestCase
{
    private TaintAnalyzer $analyzer;

    private GraphValidator $validator;

    protected function setUp(): void
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->registerMany([
            GreetNode::class,
            UpperNode::class,
            FanInNode::class,
            JsonEmitNode::class,
            UntrustedSourceNode::class,
            TrustedSinkNode::class,
            SanitizingNode::class,
        ]);

        $this->analyzer = new TaintAnalyzer($registry);
        $this->validator = new GraphValidator($registry, $this->analyzer);
    }

    public function test_a_declared_source_taints_its_own_output(): void
    {
        $map = $this->analyzer->analyze(new GraphDefinition(
            [new GraphNode('llm', 'test.untrusted_source')],
            [],
        ));

        $this->assertTrue($map->outputIsUntrusted('llm', 'text'));
        $this->assertSame(['llm.payload', 'llm.text'], $map->untrustedPorts());
    }

    public function test_a_graph_with_no_source_has_no_taint(): void
    {
        $map = $this->analyzer->analyze(new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('u', 'test.upper')],
            [new Connection('g', 'greeting', 'u', 'text')],
        ));

        $this->assertSame([], $map->untrustedPorts());
        $this->assertFalse($map->inputIsUntrusted('u', 'text'));
    }

    public function test_taint_propagates_through_a_derived_node(): void
    {
        // The whole point: passing attacker text through a formatter does
        // not make it yours.
        $map = $this->analyzer->analyze(new GraphDefinition(
            [new GraphNode('llm', 'test.untrusted_source'), new GraphNode('u', 'test.upper')],
            [new Connection('llm', 'text', 'u', 'text')],
        ));

        $this->assertTrue($map->inputIsUntrusted('u', 'text'));
        $this->assertTrue($map->outputIsUntrusted('u', 'upper'));

        // untrustedPorts() answers "what does this graph EMIT that is
        // untrusted" — outputs only. `u.text` is an untrusted input and is
        // deliberately not in this list.
        $this->assertSame(['llm.payload', 'llm.text', 'u.upper'], $map->untrustedPorts());
    }

    public function test_wiring_a_model_into_a_command_is_rejected_at_validation(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('llm', 'test.untrusted_source'), new GraphNode('sink', 'test.trusted_sink')],
            [new Connection('llm', 'text', 'sink', 'command')],
        );

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/requires trusted data but receives untrusted data originating at \[llm\.text\]/');

        $this->validator->validate($graph);
    }

    public function test_the_violation_names_the_whole_route_not_just_the_sink(): void
    {
        // Three hops, because in a real graph the fix is never at the sink.
        $graph = new GraphDefinition(
            [
                new GraphNode('llm', 'test.untrusted_source'),
                new GraphNode('u', 'test.upper'),
                new GraphNode('sink', 'test.trusted_sink'),
            ],
            [
                new Connection('llm', 'text', 'u', 'text'),
                new Connection('u', 'upper', 'sink', 'command'),
            ],
        );

        $violations = $this->analyzer->violations($graph);

        $this->assertCount(1, $violations);
        $this->assertSame('llm.text', $violations[0]->path->origin());
        $this->assertSame(
            'llm.text -> u.text -> u.upper -> sink.command',
            $violations[0]->path->render(),
        );
    }

    public function test_a_sanitizer_stops_propagation_on_the_port_that_claims_it(): void
    {
        $graph = new GraphDefinition(
            [
                new GraphNode('llm', 'test.untrusted_source'),
                new GraphNode('s', 'test.sanitize'),
                new GraphNode('sink', 'test.trusted_sink'),
            ],
            [
                new Connection('llm', 'text', 's', 'candidate'),
                new Connection('s', 'choice', 'sink', 'command'),
            ],
        );

        $map = $this->analyzer->analyze($graph);

        $this->assertTrue($map->inputIsUntrusted('s', 'candidate'), 'The sanitizer still RECEIVES untrusted data.');
        $this->assertFalse($map->outputIsUntrusted('s', 'choice'), 'The trusted port launders it.');
        $this->assertSame([], $this->analyzer->violations($graph));

        $this->validator->validate($graph);
        $this->addToAssertionCount(1);
    }

    public function test_a_sanitizer_does_not_launder_its_other_ports(): void
    {
        // The near-miss that makes the feature worth having: the author
        // sanitized, then wired the WRONG output of the sanitizer.
        $graph = new GraphDefinition(
            [
                new GraphNode('llm', 'test.untrusted_source'),
                new GraphNode('s', 'test.sanitize'),
                new GraphNode('sink', 'test.trusted_sink'),
            ],
            [
                new Connection('llm', 'text', 's', 'candidate'),
                new Connection('s', 'echo', 'sink', 'command'),
            ],
        );

        $this->assertTrue($this->analyzer->analyze($graph)->outputIsUntrusted('s', 'echo'));
        $this->assertCount(1, $this->analyzer->violations($graph));
    }

    public function test_an_untrusted_input_taints_every_derived_output_of_that_node(): void
    {
        // A node cannot keep one output clean by convention: if anything
        // untrusted went in, everything not explicitly sanitized is out.
        $map = $this->analyzer->analyze(new GraphDefinition(
            [new GraphNode('llm', 'test.untrusted_source'), new GraphNode('sink', 'test.trusted_sink')],
            [new Connection('llm', 'text', 'sink', 'note')],
        ));

        $this->assertTrue($map->outputIsUntrusted('sink', 'result'));
    }

    public function test_a_port_that_does_not_require_trust_accepts_untrusted_data(): void
    {
        // Taint is not a ban. Most ports are allowed to carry attacker
        // text — quoting it back to a user is the normal case.
        $graph = new GraphDefinition(
            [new GraphNode('llm', 'test.untrusted_source'), new GraphNode('sink', 'test.trusted_sink', ['command' => 'ls'])],
            [new Connection('llm', 'text', 'sink', 'note')],
        );

        $this->assertSame([], $this->analyzer->violations($graph));
        $this->validator->validate($graph);
        $this->addToAssertionCount(1);
    }

    public function test_a_config_literal_is_trusted_because_the_author_wrote_it(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('sink', 'test.trusted_sink', ['command' => 'ls'])],
            [],
        );

        $this->assertSame([], $this->analyzer->violations($graph));
        $this->assertFalse($this->analyzer->analyze($graph)->inputIsUntrusted('sink', 'command'));
    }

    public function test_one_untrusted_wire_taints_a_whole_fan_in_port(): void
    {
        // A `multiple` port coalesces its wires into one list. There is no
        // per-element taint, because a handler holding the list cannot be
        // assumed to keep the clean elements apart from the dirty one.
        $map = $this->analyzer->analyze(new GraphDefinition(
            [
                new GraphNode('clean', 'test.jsonemit'),
                new GraphNode('llm', 'test.untrusted_source'),
                new GraphNode('fan', 'test.fanin'),
            ],
            [
                new Connection('clean', 'data', 'fan', 'items'),
                new Connection('llm', 'payload', 'fan', 'items'),
            ],
        ));

        $this->assertTrue($map->inputIsUntrusted('fan', 'items'));
    }

    public function test_a_cycle_is_rejected_before_the_analysis_ever_sees_it(): void
    {
        // Worth pinning as a test rather than assuming: the analyzer's
        // "no topological order means no answer" guard exists because
        // topologicalOrder() documents that it returns empty on a cycle —
        // but GraphDefinition refuses to construct one in the first place,
        // so no cyclic graph reaches the analysis at all. If that ever
        // changes, this test fails and the guard becomes load-bearing.
        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        new GraphDefinition(
            [new GraphNode('a', 'test.upper'), new GraphNode('b', 'test.upper')],
            [new Connection('a', 'upper', 'b', 'text'), new Connection('b', 'upper', 'a', 'text')],
        );
    }

    public function test_the_topological_order_covers_every_node(): void
    {
        // The invariant propagation rests on. If GraphDefinition ever
        // returned a partial order, the analyzer would skip nodes and call
        // their untrusted outputs trusted — so the assumption is pinned
        // here rather than left implicit in a comment.
        $graph = new GraphDefinition(
            [
                new GraphNode('llm', 'test.untrusted_source'),
                new GraphNode('u', 'test.upper'),
                new GraphNode('s', 'test.sanitize'),
                new GraphNode('sink', 'test.trusted_sink'),
            ],
            [
                new Connection('llm', 'text', 'u', 'text'),
                new Connection('u', 'upper', 's', 'candidate'),
                new Connection('s', 'choice', 'sink', 'command'),
            ],
        );

        $this->assertCount(count($graph->nodes), $graph->topologicalOrder());
    }

    public function test_taint_is_not_reported_on_a_structurally_broken_graph(): void
    {
        // An unknown node type must surface as itself, not buried under a
        // speculative security message about a structure that does not hold.
        try {
            $this->validator->validate(new GraphDefinition(
                [new GraphNode('llm', 'test.untrusted_source'), new GraphNode('x', 'missing.type')],
                [new Connection('llm', 'text', 'x', 'anything')],
            ));
            $this->fail('Expected InvalidGraphException.');
        } catch (InvalidGraphException $e) {
            $joined = implode(' | ', $e->violations());
            $this->assertStringContainsString('Unknown node type', $joined);
            $this->assertStringNotContainsString('requires trusted data', $joined);
        }
    }
}
