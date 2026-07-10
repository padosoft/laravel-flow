<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor;

use Padosoft\LaravelFlow\Executor\ContentHasher;
use PHPUnit\Framework\TestCase;

final class ContentHasherTest extends TestCase
{
    private function hasher(): ContentHasher
    {
        return new ContentHasher;
    }

    public function test_hash_stable_across_input_key_order(): void
    {
        // Associative (dict) keys are order-insensitive: the same map written in
        // a different key order must hash identically.
        $a = $this->hasher()->hash('test.node', ['b' => 2, 'a' => 1], ['y' => 'n', 'x' => 'm']);
        $b = $this->hasher()->hash('test.node', ['a' => 1, 'b' => 2], ['x' => 'm', 'y' => 'n']);

        $this->assertSame($a, $b);
    }

    public function test_hash_differs_on_value_change(): void
    {
        $a = $this->hasher()->hash('test.node', ['a' => 1], []);
        $b = $this->hasher()->hash('test.node', ['a' => 2], []);

        $this->assertNotSame($a, $b);
    }

    public function test_hash_differs_on_type_change(): void
    {
        $a = $this->hasher()->hash('test.a', ['a' => 1], []);
        $b = $this->hasher()->hash('test.b', ['a' => 1], []);

        $this->assertNotSame($a, $b);
    }

    public function test_list_order_semantics_pinned(): void
    {
        // LIST order IS significant for node inputs (fan-in ports are ordered
        // lists coalesced by topological index): [1,2] and [2,1] must differ.
        $a = $this->hasher()->hash('test.node', ['items' => [1, 2]], []);
        $b = $this->hasher()->hash('test.node', ['items' => [2, 1]], []);
        $same = $this->hasher()->hash('test.node', ['items' => [1, 2]], []);

        $this->assertNotSame($a, $b, 'differently-ordered lists hash differently');
        $this->assertSame($a, $same, 'equal lists in equal order hash the same');
    }

    public function test_int_and_float_hash_differently(): void
    {
        // A float 1.0 is semantically distinct from an int 1 for a handler, so
        // they must not collide into the same cache entry.
        $int = $this->hasher()->hash('test.node', ['value' => 1], []);
        $float = $this->hasher()->hash('test.node', ['value' => 1.0], []);

        $this->assertNotSame($int, $float);
    }

    public function test_nested_dict_keys_sorted_recursively(): void
    {
        $a = $this->hasher()->hash('test.node', ['outer' => ['b' => 2, 'a' => 1]], []);
        $b = $this->hasher()->hash('test.node', ['outer' => ['a' => 1, 'b' => 2]], []);

        $this->assertSame($a, $b);
    }
}
