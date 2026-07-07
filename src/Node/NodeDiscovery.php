<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Scans a PSR-4 mapped directory for #[FlowNode]-attributed classes
 * implementing {@see FlowNodeHandler}. Classes are resolved through the
 * autoloader, never by including files directly.
 *
 * @api
 */
final class NodeDiscovery
{
    /**
     * @return list<class-string> sorted FQCNs
     */
    public function discover(string $path, string $namespace): array
    {
        $realPath = realpath($path);

        if ($realPath === false || ! is_dir($realPath)) {
            return [];
        }

        // realpath() strips trailing separators except on filesystem roots
        // ("C:\", "/"): build the prefix explicitly so the relative-path
        // offset below is correct in both cases, without ever mutating the
        // (always valid) iterator path.
        $prefix = str_ends_with($realPath, DIRECTORY_SEPARATOR)
            ? $realPath
            : $realPath.DIRECTORY_SEPARATOR;

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($prefix), -4);
            $class = rtrim($namespace, '\\').'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(FlowNode::class) === []) {
                continue;
            }

            if (! $reflection->implementsInterface(FlowNodeHandler::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }
}
