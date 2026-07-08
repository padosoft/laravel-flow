<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `src/Node` MUST stay framework-free: the node contract, registry,
 * discovery and catalog are consumed by non-Laravel tooling (Studio,
 * MCP schema generation). Laravel-specific wiring (the `flow:nodes`
 * command, container bindings) lives in `Console`/the service provider.
 */
final class NodeNamespaceTest extends TestCase
{
    public function test_node_namespace_is_framework_free(): void
    {
        $nodeRoot = realpath(__DIR__.'/../../src/Node');
        $this->assertNotFalse($nodeRoot, 'src/Node directory must exist');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nodeRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $offenders = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'use Illuminate\\')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Illuminate imports found in src/Node:\n%s\nsrc/Node must stay standalone-agnostic; framework wiring belongs in Console.",
            implode("\n", $offenders),
        ));
    }
}
