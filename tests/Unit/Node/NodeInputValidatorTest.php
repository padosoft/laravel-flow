<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;
use Padosoft\LaravelFlow\Node\NodeDefinition;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeInputHydrator;
use Padosoft\LaravelFlow\Node\NodeInputValidator;
use Padosoft\LaravelFlow\Node\PortDefinition;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class NodeInputValidatorTest extends TestCase
{
    private NodeInputValidator $validator;

    private object $handler;

    protected function setUp(): void
    {
        $this->validator = new NodeInputValidator;
        $this->handler = new #[FlowNode(type: 'test.validate')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public int $count;

            #[Input(type: PortType::Text, key: 'note')]
            public ?string $comment = null;
        };
    }

    private function definition(): NodeDefinition
    {
        return (new NodeDefinitionFactory)->fromClass($this->handler::class);
    }

    public function test_valid_inputs_pass_through(): void
    {
        $validated = $this->validator->validate($this->definition(), ['count' => 3, 'note' => 'hi']);

        $this->assertSame(['count' => 3, 'note' => 'hi'], $validated);
    }

    public function test_optional_input_may_be_absent(): void
    {
        $this->assertSame(['count' => 1], $this->validator->validate($this->definition(), ['count' => 1]));
    }

    public function test_missing_required_input_violates(): void
    {
        try {
            $this->validator->validate($this->definition(), ['note' => 'hi']);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertArrayHasKey('count', $e->violations());
            $this->assertStringContainsString('required', $e->violations()['count'][0]);
        }
    }

    public function test_type_mismatch_violates_per_port(): void
    {
        try {
            $this->validator->validate($this->definition(), ['count' => 'three', 'note' => 42]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertSame(['count', 'note'], array_keys($e->violations()));
        }
    }

    public function test_unknown_input_key_violates(): void
    {
        try {
            $this->validator->validate($this->definition(), ['count' => 1, 'ghost' => true]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertArrayHasKey('_unknown', $e->violations());
            $this->assertStringContainsString('ghost', $e->violations()['_unknown'][0]);
        }
    }

    public function test_hydrator_assigns_validated_inputs_to_properties(): void
    {
        $definition = $this->definition();
        $validated = $this->validator->validate($definition, ['count' => 5, 'note' => 'ciao']);

        (new NodeInputHydrator)->hydrate($this->handler, $definition, $validated);

        $this->assertSame(5, $this->handler->count);
        $this->assertSame('ciao', $this->handler->comment);
    }

    public function test_explicit_null_on_optional_input_is_treated_as_absent(): void
    {
        $validated = $this->validator->validate($this->definition(), ['count' => 1, 'note' => null]);

        $this->assertSame(['count' => 1], $validated);
    }

    public function test_explicit_null_on_required_input_violates(): void
    {
        try {
            $this->validator->validate($this->definition(), ['count' => null]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertArrayHasKey('count', $e->violations());
        }
    }

    public function test_explicit_null_on_required_any_input_violates(): void
    {
        $handler = new #[FlowNode(type: 'test.any')] class
        {
            #[Input(type: PortType::Any, required: true)]
            public mixed $data;
        };
        $definition = (new NodeDefinitionFactory)->fromClass($handler::class);

        try {
            $this->validator->validate($definition, ['data' => null]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertArrayHasKey('data', $e->violations());
            $this->assertStringContainsString('must not be null', $e->violations()['data'][0]);
        }
    }

    /**
     * @param  list<PortDefinition>  $inputs
     */
    private function definitionWith(array $inputs): NodeDefinition
    {
        return new NodeDefinition('t.multi', 'Multi', 'test', null, null, $inputs, [], 'Handler');
    }

    public function test_multiple_port_validates_a_list_of_items(): void
    {
        $definition = $this->definitionWith([new PortDefinition('items', PortType::Json, false, null, null, true)]);

        $validated = $this->validator->validate($definition, ['items' => [['a' => 1], ['b' => 2]]]);

        $this->assertSame(['items' => [['a' => 1], ['b' => 2]]], $validated);
    }

    public function test_multiple_port_rejects_a_non_list_value(): void
    {
        $definition = $this->definitionWith([new PortDefinition('items', PortType::Json, false, null, null, true)]);

        try {
            $this->validator->validate($definition, ['items' => 'not-a-list']);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertStringContainsString('must be a list', $e->violations()['items'][0]);
        }
    }

    public function test_multiple_port_rejects_an_associative_array(): void
    {
        $definition = $this->definitionWith([new PortDefinition('items', PortType::Json, false, null, null, true)]);

        try {
            $this->validator->validate($definition, ['items' => ['first' => ['a' => 1]]]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertStringContainsString('must be a list', $e->violations()['items'][0]);
        }
    }

    public function test_multiple_port_rejects_an_item_of_the_wrong_type(): void
    {
        $definition = $this->definitionWith([new PortDefinition('items', PortType::Json, false, null, null, true)]);

        try {
            $this->validator->validate($definition, ['items' => [['ok' => true], 'scalar']]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertStringContainsString('items][1]', $e->violations()['items'][0]);
        }
    }

    public function test_required_multiple_port_rejects_an_empty_list(): void
    {
        $definition = $this->definitionWith([new PortDefinition('items', PortType::Json, true, null, null, true)]);

        try {
            $this->validator->validate($definition, ['items' => []]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertStringContainsString('must not be an empty list', $e->violations()['items'][0]);
        }
    }
}
