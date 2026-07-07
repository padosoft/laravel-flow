<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use ReflectionClass;

/**
 * Builds {@see NodeDefinition}s from attribute-annotated handler classes.
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

                $inputs[$key] = new PortDefinition($key, $input->type, $input->required, $input->label, $property->getName());
            }

            foreach ($property->getAttributes(Output::class) as $attribute) {
                $output = $attribute->newInstance();
                $key = $output->key ?? $property->getName();

                if (isset($outputs[$key])) {
                    throw new InvalidNodeDefinitionException("Duplicate output port [{$key}] on [{$class}].");
                }

                $outputs[$key] = new PortDefinition($key, $output->type, false, $output->label, $property->getName());
            }
        }

        return [array_values($inputs), array_values($outputs)];
    }
}
