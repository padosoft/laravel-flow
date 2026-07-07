<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use InvalidArgumentException;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use ReflectionClass;

/**
 * Builds {@see NodeDefinition}s from attribute-annotated handler classes.
 *
 * Optional (`required: false`) input properties must declare a default
 * value: the hydrator only assigns validated inputs that are present, so an
 * optional input left absent from the payload would otherwise leave a typed
 * property uninitialized and fatal on later reads. Required inputs need no
 * default because the hydrator always assigns them once validation passes.
 *
 * Hydratability contract: `#[Input]` properties must be public and not
 * readonly so {@see NodeInputHydrator} can assign them from outside, and
 * `#[Output]` properties must be public (readonly allowed) so the executor
 * can read them off the handler after execute.
 *
 * @api
 */
final class NodeDefinitionFactory
{
    /**
     * @param  class-string  $class
     */
    public function fromClass(string $class): NodeDefinition
    {
        if (! class_exists($class)) {
            throw new InvalidNodeDefinitionException("Node handler class [{$class}] does not exist.");
        }

        $reflection = new ReflectionClass($class);
        $nodeAttributes = $reflection->getAttributes(FlowNode::class);

        if ($nodeAttributes === []) {
            throw new InvalidNodeDefinitionException("Class [{$class}] is missing the #[FlowNode] attribute.");
        }

        $node = $nodeAttributes[0]->newInstance();

        [$inputs, $outputs] = $this->collectPorts($reflection, $class);

        return new NodeDefinition(
            type: $node->type,
            name: $node->name ?? $reflection->getShortName(),
            category: $node->category,
            icon: $node->icon,
            description: $node->description,
            inputs: $inputs,
            outputs: $outputs,
            handlerClass: $class,
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array{0: list<PortDefinition>, 1: list<PortDefinition>}
     */
    private function collectPorts(ReflectionClass $reflection, string $class): array
    {
        $inputs = [];
        $outputs = [];

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Input::class) as $attribute) {
                $input = $attribute->newInstance();
                $key = $input->key ?? $property->getName();

                if (isset($inputs[$key])) {
                    throw new InvalidNodeDefinitionException("Duplicate input port [{$key}] on [{$class}].");
                }

                if (! $property->isPublic() || $property->isReadOnly()) {
                    throw new InvalidNodeDefinitionException("Input property [{$class}::\${$property->getName()}] must be public and not readonly.");
                }

                try {
                    $port = new PortDefinition($key, $input->type, $input->required, $input->label, $property->getName());
                } catch (InvalidArgumentException $e) {
                    throw new InvalidNodeDefinitionException("Invalid input port on [{$class}::\${$property->getName()}]: {$e->getMessage()}", previous: $e);
                }

                if (! $input->required && ! $property->hasDefaultValue()) {
                    throw new InvalidNodeDefinitionException("Optional input property [{$class}::\${$property->getName()}] must declare a default value.");
                }

                $inputs[$key] = $port;
            }

            foreach ($property->getAttributes(Output::class) as $attribute) {
                $output = $attribute->newInstance();
                $key = $output->key ?? $property->getName();

                if (isset($outputs[$key])) {
                    throw new InvalidNodeDefinitionException("Duplicate output port [{$key}] on [{$class}].");
                }

                if (! $property->isPublic()) {
                    throw new InvalidNodeDefinitionException("Output property [{$class}::\${$property->getName()}] must be public.");
                }

                try {
                    $outputs[$key] = new PortDefinition($key, $output->type, false, $output->label, $property->getName());
                } catch (InvalidArgumentException $e) {
                    throw new InvalidNodeDefinitionException("Invalid output port on [{$class}::\${$property->getName()}]: {$e->getMessage()}", previous: $e);
                }
            }
        }

        return [array_values($inputs), array_values($outputs)];
    }
}
