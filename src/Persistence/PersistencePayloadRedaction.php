<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;

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
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function redactFields(PayloadRedactor $redactor, array $attributes, array $fields): array
    {
        $redact = static function () use ($redactor, $attributes, $fields): array {
            foreach ($fields as $key) {
                if (isset($attributes[$key]) && is_array($attributes[$key])) {
                    /** @var array<string, mixed> $payload */
                    $payload = $attributes[$key];
                    $attributes[$key] = $redactor->redact($payload);
                }
            }

            return $attributes;
        };

        if ($redactor instanceof ExecutionScopedPayloadRedactor) {
            return $redactor->usingCurrentRedactor($redact);
        }

        return $redact();
    }
}
