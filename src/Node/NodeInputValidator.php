<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;

/**
 * Enforces a node's input port contract BEFORE the handler runs, so a
 * malformed payload can never burn a side effect or provider call.
 *
 * An explicit `null` never enters the validated array: on an optional
 * (`required: false`) port it is treated exactly like an absent key —
 * skipped, not a violation — while on a required port it is always a
 * violation, regardless of the port type (including {@see PortType::Any}).
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

            if ($value === null) {
                if ($port->required) {
                    $violations[$port->key][] = "Input [{$port->key}] is required and must not be null.";
                }

                continue;
            }

            if ($port->multiple) {
                if (! is_array($value) || ! array_is_list($value)) {
                    $violations[$port->key][] = "Input [{$port->key}] is a multiple port and must be a list, got [".get_debug_type($value).'].';

                    continue;
                }

                if ($port->required && $value === []) {
                    $violations[$port->key][] = "Input [{$port->key}] is required and must not be an empty list.";

                    continue;
                }

                $itemViolation = false;
                foreach ($value as $index => $item) {
                    if (! $port->type->validates($item)) {
                        $violations[$port->key][] = "Input [{$port->key}][{$index}] must be of type [{$port->type->value}], got [".get_debug_type($item).'].';
                        $itemViolation = true;
                    }
                }

                if ($itemViolation) {
                    continue;
                }

                $validated[$port->key] = $value; // already a list (array_is_list checked above)

                continue;
            }

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
