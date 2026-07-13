<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Broadcasting is an opt-in capability contained to `src/Broadcasting/`. No
 * other file under `src/` may reference Laravel's broadcasting contracts —
 * keeps the dependency (and a future "rip out broadcasting" change) confined
 * to one directory, and proves the rest of the package has no hard runtime
 * coupling to a configured broadcast driver.
 */
final class BroadcastingIsolationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_SUBSTRINGS = [
        'Illuminate\Broadcasting',
        'Illuminate\Contracts\Broadcasting',
        'Broadcast::',
    ];

    public function test_broadcasting_references_are_confined_to_the_broadcasting_namespace(): void
    {
        $srcRoot = realpath(__DIR__.'/../../src');
        $this->assertNotFalse($srcRoot, 'src/ directory must exist');

        $broadcastingRoot = realpath(__DIR__.'/../../src/Broadcasting');
        $this->assertNotFalse($broadcastingRoot, 'src/Broadcasting/ directory must exist');

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $offenders = [];

        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Anything inside src/Broadcasting/ is exempt — that IS the
            // contained namespace.
            if (str_starts_with($file->getPathname(), $broadcastingRoot)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s -> %s', $file->getPathname(), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Broadcasting references found outside src/Broadcasting/:\n%s\nThe rest of the package must stay decoupled from a specific broadcasting mechanism — route through Broadcasting\\GraphProgressBroadcaster instead.",
            implode("\n", $offenders),
        ));
    }
}
