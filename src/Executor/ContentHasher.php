<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

/**
 * Pure, framework-free content hash for the node cache: a stable sha256 over a
 * canonicalized `{type, inputs, config}` triple. Associative (dict) keys are
 * sorted recursively so a differently-ordered-but-equal input map hashes the
 * same; LIST order is DELIBERATELY preserved — unlike graph-structure hashing
 * (where node/connection order is non-semantic), a node's `multiple` (fan-in)
 * ports are ORDERED lists coalesced by topological index, so `[a, b]` and
 * `[b, a]` MUST hash differently or a cache hit could return the wrong output.
 *
 * @internal
 */
final class ContentHasher
{
    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $config
     */
    public function hash(string $type, array $inputs, array $config): string
    {
        $canonical = [
            'type' => $type,
            'inputs' => $this->canonicalize($inputs),
            'config' => $this->canonicalize($config),
        ];

        // JSON_PRESERVE_ZERO_FRACTION keeps a float 1.0 distinct from an int 1
        // (both would otherwise encode as `1`), so semantically distinct
        // int/float inputs never collide into the same cache entry.
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // A list is order-significant here (fan-in port semantics): keep the
        // order, only canonicalize each element.
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        return $canonical;
    }
}
