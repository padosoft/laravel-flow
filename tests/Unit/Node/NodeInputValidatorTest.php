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
}
