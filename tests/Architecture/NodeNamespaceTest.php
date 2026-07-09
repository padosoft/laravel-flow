<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `src/Node` and `src/Graph` MUST stay framework-free: the node contract,
 * registry, discovery, catalog, graph definition and validator are
 * consumed by non-Laravel tooling (Studio, MCP schema generation).
 * Laravel-specific wiring (the `flow:nodes` command, container bindings)
 * lives in `Console`/the service provider.
 */
final class NodeNamespaceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function frameworkFreeRoots(): iterable
    {
        yield 'Node' => ['Node'];
        yield 'Graph' => ['Graph'];
    }

    #[DataProvider('frameworkFreeRoots')]
    public function test_namespace_is_framework_free(string $relativeRoot): void
    {
        $root = realpath(__DIR__.'/../../src/'.$relativeRoot);

        if ($root === false) {
            $this->markTestSkipped("src/{$relativeRoot} does not exist yet.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $offenders = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Word boundary catches imports AND fully-qualified inline
            // references (type hints, instanceof, catch) alike.
            if (preg_match('/\bIlluminate\\\\/', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Illuminate references found in src/%s:\n%s\nsrc/%s must stay standalone-agnostic; framework wiring belongs in Console.",
            $relativeRoot,
            implode("\n", $offenders),
            $relativeRoot,
        ));
    }
}
