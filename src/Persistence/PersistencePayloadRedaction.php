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
        $payloads = [];

        foreach ($fields as $key) {
            if (isset($attributes[$key]) && is_array($attributes[$key])) {
                /** @var array<string, mixed> $payload */
                $payload = $attributes[$key];
                $payloads[$key] = $payload;
            }
        }

        if ($payloads === []) {
            return $attributes;
        }

        $redacted = $redactor->redact($payloads);

        foreach (array_keys($payloads) as $key) {
            if (array_key_exists($key, $redacted)) {
                $attributes[$key] = $redacted[$key];
            }
        }

        return $attributes;
    }
}
