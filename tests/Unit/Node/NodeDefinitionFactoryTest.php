<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\PortType;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\EmptyTypeNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use PHPUnit\Framework\TestCase;

final class NodeDefinitionFactoryTest extends TestCase
{
    private NodeDefinitionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new NodeDefinitionFactory;
    }

    public function test_builds_definition_from_attributes(): void
    {
        $handler = new #[FlowNode(type: 'billing.refund', category: 'billing', icon: 'credit-card')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public int $orderId;

            #[Input(type: PortType::Float, key: 'amount', label: 'Refund amount')]
            public ?float $refundAmount = null;

            #[Output(type: PortType::Json)]
            public array $receipt;
        };

        $definition = $this->factory->fromClass($handler::class);

        $this->assertSame('billing.refund', $definition->type);
        $this->assertSame('billing', $definition->category);
        $this->assertSame('credit-card', $definition->icon);
        $this->assertCount(2, $definition->inputs);
        $this->assertCount(1, $definition->outputs);

        $orderId = $definition->input('orderId');
        $this->assertNotNull($orderId);
        $this->assertSame(PortType::Int, $orderId->type);
        $this->assertTrue($orderId->required);
        $this->assertSame('orderId', $orderId->propertyName);

        $amount = $definition->input('amount');
        $this->assertNotNull($amount);
        $this->assertSame('refundAmount', $amount->propertyName);
        $this->assertSame('Refund amount', $amount->label);

        $this->assertNotNull($definition->output('receipt'));
        $this->assertNull($definition->input('missing'));
    }

    public function test_multiple_flag_threads_to_port_definition(): void
    {
        $handler = new #[FlowNode(type: 'fanin.node')] class
        {
            #[Input(type: PortType::Json, multiple: true)]
            public array $items = [];

            #[Input(type: PortType::Text, required: true)]
            public string $label;
        };

        $definition = $this->factory->fromClass($handler::class);

        $items = $definition->input('items');
        $this->assertNotNull($items);
        $this->assertTrue($items->multiple);
        $this->assertTrue($definition->input('items')->toArray()['multiple']);

        // A normal port stays multiple: false.
        $this->assertFalse($definition->input('label')->multiple);
    }

    public function test_multiple_on_scalar_port_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'bad.fanin')] class
        {
            #[Input(type: PortType::Text, multiple: true)]
            public array $items = [];
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessage('must be of port type json or any');

        $this->factory->fromClass($handler::class);
    }

    public function test_multiple_port_requires_array_property(): void
    {
        $handler = new #[FlowNode(type: 'bad.fanin.type')] class
        {
            #[Input(type: PortType::Json, multiple: true)]
            public string $items = '';
        };

        $this->expectException(InvalidNodeDefinitionException::class);

        $this->factory->fromClass($handler::class);
    }

    public function test_name_defaults_to_class_basename(): void
    {
        $definition = $this->factory->fromClass(GreetNode::class);

        $this->assertSame('GreetNode', $definition->name);
        $this->assertSame(GreetNode::class, $definition->handlerClass);
    }

    public function test_to_array_exposes_catalog_shape(): void
    {
        $array = $this->factory->fromClass(GreetNode::class)->toArray();

        $this->assertSame('test.greet', $array['type']);
        $this->assertSame(
            [['key' => 'name', 'type' => 'text', 'required' => true, 'label' => 'name', 'multiple' => false]],
            $array['inputs'],
        );
        $this->assertArrayHasKey('outputs', $array);
        $this->assertArrayNotHasKey('handlerClass', $array);
    }

    public function test_rejects_class_without_flow_node_attribute(): void
    {
        $plain = new class {};

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/missing.*FlowNode/i');
        $this->factory->fromClass($plain::class);
    }

    public function test_rejects_unknown_class(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->factory->fromClass('App\\Does\\Not\\Exist');
    }

    // NOTE (A-PR1 local review): empty-type rejection now lives in the
    // FlowNode attribute constructor itself and is covered by
    // AttributesTest::test_flow_node_rejects_empty_type. No factory-level
    // empty-type test or guard is needed here.

    public function test_rejects_duplicate_input_port_keys(): void
    {
        $handler = new #[FlowNode(type: 'dup.node')] class
        {
            #[Input(type: PortType::Text, key: 'same', required: true)]
            public string $a;

            #[Input(type: PortType::Text, key: 'same', required: true)]
            public string $b;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/duplicate input port.*same/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_rejects_duplicate_output_port_keys(): void
    {
        $handler = new #[FlowNode(type: 'dup.out')] class
        {
            #[Output(type: PortType::Text, key: 'same')]
            public string $a;

            #[Output(type: PortType::Text, key: 'same')]
            public string $b;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/duplicate output port.*same/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_wraps_malformed_input_attribute_payload(): void
    {
        $handler = new #[FlowNode(type: 'bad.attrpayload')] class
        {
            #[Input(type: 'text')]
            public string $a;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/invalid #\[Input\]/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_wraps_invalid_port_key_from_attribute(): void
    {
        $handler = new #[FlowNode(type: 'bad.port')] class
        {
            #[Input(type: PortType::Text, key: '_bad')]
            public string $a;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/invalid input port .*reserved/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_optional_input_without_default_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'opt.nodefault')] class
        {
            #[Input(type: PortType::Text)]
            public string $x;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/must declare a default value/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_non_public_input_property_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'vis.in')] class
        {
            #[Input(type: PortType::Text, required: true)]
            private string $a;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/must be public and not readonly/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_readonly_input_property_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'ro.in')] class
        {
            #[Input(type: PortType::Text, required: true)]
            public readonly string $a;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/must be public and not readonly/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_non_public_output_property_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'vis.out')] class
        {
            #[Output(type: PortType::Text)]
            protected string $o;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/must be public/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_static_input_property_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'static.in')] class
        {
            #[Input(type: PortType::Text, required: true)]
            public static string $a;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/must be an instance property/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_static_output_property_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'static.out')] class
        {
            #[Output(type: PortType::Text)]
            public static string $o;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/must be an instance property/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_wraps_invalid_flow_node_attribute_payload(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/invalid #\[FlowNode\].*type must not be empty/i');
        $this->factory->fromClass(EmptyTypeNode::class);
    }

    public function test_incompatible_property_type_for_port_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'compat.bad')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public string $count;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/cannot hold values of port type \[int\]/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_any_port_requires_untyped_or_mixed_property(): void
    {
        $handler = new #[FlowNode(type: 'compat.any')] class
        {
            #[Input(type: PortType::Any, required: true)]
            public string $data;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/cannot hold values of port type \[any\]/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_int_property_on_float_port_is_rejected(): void
    {
        // Float ports deliver ints AND floats: a real float would TypeError
        // on write into an int property under strict_types.
        $handler = new #[FlowNode(type: 'compat.floatint')] class
        {
            #[Input(type: PortType::Float, required: true)]
            public int $ratio;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/cannot hold values of port type \[float\]/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_float_property_on_int_port_is_accepted(): void
    {
        // Int ports only deliver ints; int→float widening is legal on write.
        $handler = new #[FlowNode(type: 'compat.intfloat')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public float $count;
        };

        $definition = $this->factory->fromClass($handler::class);

        $this->assertSame(PortType::Int, $definition->input('count')?->type);
    }

    public function test_compatible_property_types_pass(): void
    {
        $handler = new #[FlowNode(type: 'compat.ok')] class
        {
            #[Input(type: PortType::Float, required: true)]
            public float $ratio;

            #[Input(type: PortType::Json, key: 'meta')]
            public ?array $metadata = null;

            #[Input(type: PortType::Any, key: 'blob')]
            public mixed $blob = null;

            #[Input(type: PortType::Text, key: 'label')]
            public string|int $labelish = '';

            #[Output(type: PortType::Int)]
            public int $total;
        };

        $definition = $this->factory->fromClass($handler::class);

        $this->assertCount(4, $definition->inputs);
        $this->assertCount(1, $definition->outputs);
    }
}
