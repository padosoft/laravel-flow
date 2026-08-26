<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

use JsonException;
use Padosoft\LaravelFlow\Executor\ContentHasher;

/**
 * Deterministic JSON for the bundle digest.
 *
 * Same rule as {@see ContentHasher}, and for the
 * same reason: object key order carries no meaning, so two encoders of the same
 * document must agree, while LIST order does carry meaning — a run's nodes are
 * a sequence, and sorting them would erase exactly the thing a forensic record
 * is for.
 *
 * `JSON_PRESERVE_ZERO_FRACTION` keeps a float `1.0` distinct from an int `1`,
 * so a payload whose numeric type mattered does not silently digest the same
 * as one where it did not.
 *
 * @internal
 */
final class ForensicCanonicalJson
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        return array_map(self::canonicalize(...), $value);
    }
}
