<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

/**
 * @internal
 */
final class PersistencePayloadRedaction
{
    /**
     * @var list<string>
     */
    public const RUN_JSON_FIELDS = ['business_impact', 'input', 'output'];

    /**
     * @var list<string>
     */
    public const STEP_JSON_FIELDS = ['business_impact', 'input', 'output'];

    /**
     * @var list<string>
     */
    public const APPROVAL_JSON_FIELDS = ['actor', 'payload'];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function redactFields(PayloadRedactor $redactor, array $attributes, array $fields): array
    {
        $redactableFields = [];

        foreach ($fields as $key) {
            if (isset($attributes[$key]) && is_array($attributes[$key])) {
                $redactableFields[] = $key;
            }
        }

        if ($redactableFields === []) {
            return $attributes;
        }

        $redactor = PayloadRedactorResolution::current($redactor);

        foreach ($redactableFields as $key) {
            /** @var array<string, mixed> $payload */
            $payload = $attributes[$key];
            $attributes[$key] = $redactor->redact($payload);
        }

        return $attributes;
    }
}
