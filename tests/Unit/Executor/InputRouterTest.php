<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Padosoft\LaravelFlow\Executor\InputRouter;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\PortDefinition;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class InputRouterTest extends TestCase
{
    /**
     * @param  list<PortDefinition>  $inputs
     */
    private function definition(array $inputs): NodeDefinition
    {
        return new NodeDefinition('t.node', 'Node', 'test', null, null, $inputs, [], 'Handler');
    }

    public function test_single_wire_maps_value(): void
    {
        $definition = $this->definition([new PortDefinition('in', PortType::Json, true)]);
        $node = new GraphNode('n', 't.node');

        $routed = (new InputRouter)->route(
            $definition,
            $node,
            [new Connection('src', 'out', 'n', 'in')],
            ['src' => ['out' => ['x' => 1]]],
        );

        $this->assertTrue($routed->valid);
        $this->assertSame(['in' => ['x' => 1]], $routed->inputs);
    }

    public function test_config_literal_satisfies_unwired_port(): void
    {
        $definition = $this->definition([new PortDefinition('in', PortType::Json, false)]);
        $node = new GraphNode('n', 't.node', ['in' => ['y' => 2]]);

        $routed = (new InputRouter)->route($definition, $node, [], []);

        $this->assertTrue($routed->valid);
        $this->assertSame(['in' => ['y' => 2]], $routed->inputs);
    }

    public function test_multiple_port_coalesces_ordered_list(): void
    {
        $definition = $this->definition([new PortDefinition('items', PortType::Json, false, null, null, true)]);
        $node = new GraphNode('n', 't.node');
        $upstream = ['s1' => ['out' => ['a' => 1]], 's2' => ['out' => ['b' => 2]]];

        $forward = (new InputRouter)->route($definition, $node, [
            new Connection('s1', 'out', 'n', 'items'),
            new Connection('s2', 'out', 'n', 'items'),
        ], $upstream);
        $this->assertTrue($forward->valid);
        $this->assertSame(['items' => [['a' => 1], ['b' => 2]]], $forward->inputs);

        // Coalesced order follows the connection order supplied, not declaration.
        $reversed = (new InputRouter)->route($definition, $node, [
            new Connection('s2', 'out', 'n', 'items'),
            new Connection('s1', 'out', 'n', 'items'),
        ], $upstream);
        $this->assertSame(['items' => [['b' => 2], ['a' => 1]]], $reversed->inputs);
    }

    public function test_multiple_port_with_no_wires_is_empty_list(): void
    {
        $definition = $this->definition([new PortDefinition('items', PortType::Json, false, null, null, true)]);
        $node = new GraphNode('n', 't.node');

        $routed = (new InputRouter)->route($definition, $node, [], []);

        $this->assertTrue($routed->valid);
        $this->assertSame(['items' => []], $routed->inputs);
    }

    public function test_validation_failure_returns_invalid_not_throw(): void
    {
        $definition = $this->definition([new PortDefinition('in', PortType::Json, true)]);
        $node = new GraphNode('n', 't.node');

        $routed = (new InputRouter)->route($definition, $node, [], []);

        $this->assertFalse($routed->valid);
        $this->assertSame([], $routed->inputs);
        $this->assertNotNull($routed->violation);
    }

    public function test_wired_single_port_with_no_upstream_value_does_not_use_config(): void
    {
        // The port IS wired but the upstream produced no value: config must not
        // silently mask the missing output, so the port stays absent (invalid
        // because it is required here).
        $definition = $this->definition([new PortDefinition('in', PortType::Json, true)]);
        $node = new GraphNode('n', 't.node', ['in' => ['fallback' => true]]);

        $routed = (new InputRouter)->route(
            $definition,
            $node,
            [new Connection('src', 'out', 'n', 'in')],
            [], // src produced nothing
        );

        $this->assertFalse($routed->valid);
        $this->assertNotNull($routed->violation);
    }

    public function test_wired_multiple_port_with_no_values_is_empty_not_config(): void
    {
        $definition = $this->definition([new PortDefinition('items', PortType::Json, false, null, null, true)]);
        $node = new GraphNode('n', 't.node', ['items' => [['config' => true]]]);

        $routed = (new InputRouter)->route(
            $definition,
            $node,
            [new Connection('src', 'out', 'n', 'items')],
            [], // wired, but upstream produced nothing
        );

        $this->assertTrue($routed->valid);
        $this->assertSame(['items' => []], $routed->inputs);
    }

    public function test_wire_overrides_config_when_both_present(): void
    {
        $definition = $this->definition([new PortDefinition('in', PortType::Json, true)]);
        $node = new GraphNode('n', 't.node', ['in' => ['c' => 2]]);

        $routed = (new InputRouter)->route(
            $definition,
            $node,
            [new Connection('src', 'out', 'n', 'in')],
            ['src' => ['out' => ['w' => 1]]],
        );

        $this->assertTrue($routed->valid);
        $this->assertSame(['in' => ['w' => 1]], $routed->inputs);
    }
}
