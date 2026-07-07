<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Attribute;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AttributesTest extends TestCase
{
    public function test_flow_node_targets_classes_only(): void
    {
        $meta = (new ReflectionClass(FlowNode::class))->getAttributes(Attribute::class)[0]->newInstance();
        $this->assertSame(Attribute::TARGET_CLASS, $meta->flags);

        $node = new FlowNode(type: 'test.node');
        $this->assertSame('test.node', $node->type);
        $this->assertSame('general', $node->category);
        $this->assertNull($node->name);
    }

    public function test_input_and_output_target_properties_only(): void
    {
        foreach ([Input::class, Output::class] as $attribute) {
            $meta = (new ReflectionClass($attribute))->getAttributes(Attribute::class)[0]->newInstance();
            $this->assertSame(Attribute::TARGET_PROPERTY, $meta->flags, $attribute);
        }

        $input = new Input(type: PortType::Int, required: true);
        $this->assertSame(PortType::Int, $input->type);
        $this->assertTrue($input->required);
        $this->assertNull($input->key);

        $output = new Output(type: PortType::Json, key: 'result');
        $this->assertSame('result', $output->key);
    }
}
