<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Assigns validated inputs onto the handler's attributed properties, so
 * handlers read typed properties (spec §3.1) instead of a loose array.
 *
 * @api
 */
final class NodeInputHydrator
{
    /**
     * @param  array<string, mixed>  $validatedInputs  output of {@see NodeInputValidator::validate()}
     */
    public function hydrate(object $handler, NodeDefinition $definition, array $validatedInputs): void
    {
        foreach ($definition->inputs as $port) {
            if ($port->propertyName === null || ! array_key_exists($port->key, $validatedInputs)) {
                continue;
            }

            $handler->{$port->propertyName} = $validatedInputs[$port->key];
        }
    }
}
