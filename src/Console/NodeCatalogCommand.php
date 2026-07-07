<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Node\NodeCatalog;

/**
 * @internal
 */
final class NodeCatalogCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:nodes {--json : Output the catalog as JSON}';

    /**
     * @var string
     */
    protected $description = 'List registered flow node types and their ports';

    public function handle(NodeCatalog $catalog): int
    {
        if ((bool) $this->option('json')) {
            $this->line($catalog->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (array $node): array => [
                $node['type'],
                $node['category'],
                count($node['inputs']),
                count($node['outputs']),
            ],
            $catalog->toArray()['nodes'],
        );

        $this->table(['Type', 'Category', 'Inputs', 'Outputs'], $rows);

        return self::SUCCESS;
    }
}
