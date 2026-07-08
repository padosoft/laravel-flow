<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every class under src/Node and src/Graph must carry exactly one of
 *
 * @api / @internal, so a new class cannot slip past the explicit pin
 * lists in the Contract suite unannotated.
 */
final class NodeAnnotationSweepTest extends TestCase
{
    public function test_every_node_and_graph_class_is_annotated(): void
    {
        foreach (['/../../src/Node', '/../../src/Graph'] as $relative) {
            $root = realpath(__DIR__.$relative);

            if ($root === false) {
                continue; // src/Graph appears later in this macro
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                $this->assertNotFalse($source, "Unable to read {$file->getPathname()}.");

                $hasApi = str_contains($source, '@api');
                $hasInternal = str_contains($source, '@internal');

                $this->assertTrue(
                    $hasApi xor $hasInternal,
                    "{$file->getPathname()} must carry exactly one of @api / @internal.",
                );
            }
        }
    }
}
