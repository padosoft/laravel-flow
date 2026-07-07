<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;

/**
 * Enforces a node's input port contract BEFORE the handler runs, so a
 * malformed payload can never burn a side effect or provider call.
 *
 * @api
 */
final class NodeInputValidator
{
    /**
     * @param  array<string, mixed>  $inputs  keyed by input port key
     * @return array<string, mixed> validated inputs (known ports only)
     *
     * @throws NodeInputValidationException
     */
    public function validate(NodeDefinition $definition, array $inputs): array
    {
        $violations = [];
        $validated = [];
        $known = [];

        foreach ($definition->inputs as $port) {
            $known[$port->key] = true;

            if (! array_key_exists($port->key, $inputs)) {
                if ($port->required) {
                    $violations[$port->key][] = "Input [{$port->key}] is required.";
                }

                continue;
            }

            $value = $inputs[$port->key];

            if (! $port->type->validates($value)) {
                $violations[$port->key][] = "Input [{$port->key}] must be of type [{$port->type->value}], got [".get_debug_type($value).'].';

                continue;
            }

            $validated[$port->key] = $value;
        }

        foreach (array_keys($inputs) as $key) {
            if (! isset($known[$key])) {
                $violations['_unknown'][] = "Unknown input port [{$key}].";
            }
        }

        if ($violations !== []) {
            throw new NodeInputValidationException($violations);
        }

        return $validated;
    }
}
