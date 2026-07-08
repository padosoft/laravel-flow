<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use JsonException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\GraphTransfer;
use Padosoft\LaravelFlow\Graph\StoredDefinition;

/**
 * @internal
 */
final class ExportFlowDefinitionCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:export
        {name : Flow definition name}
        {--definition-version= : Explicit version to export; defaults to the latest published version}
        {--file= : Write the export to this file instead of stdout}';

    /**
     * @var string
     */
    protected $description = 'Export a stored flow definition as a portable JSON graph.';

    public function handle(DefinitionRepository $definitions, GraphTransfer $transfer): int
    {
        $name = (string) $this->argument('name');
        $rawVersion = $this->option('definition-version');

        if ($rawVersion !== null && (! is_string($rawVersion) || ! ctype_digit($rawVersion))) {
            $this->error('--definition-version must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $stored = $rawVersion !== null
                ? $definitions->find($name, (int) $rawVersion)
                : $definitions->latest($name, StoredDefinition::STATUS_PUBLISHED);
        } catch (DefinitionNotFoundException|DefinitionSignatureException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($stored === null) {
            $this->error(sprintf(
                'Flow definition [%s] has no published version to export; pass --version for an explicit draft or archived version.',
                $name,
            ));

            return self::FAILURE;
        }

        try {
            $json = $transfer->export($stored);
        } catch (JsonException $e) {
            $this->error(sprintf('Flow definition [%s] could not be encoded as JSON: %s', $name, $e->getMessage()));

            return self::FAILURE;
        }

        $file = $this->option('file');

        if (is_string($file) && trim($file) !== '') {
            if (@file_put_contents($file, $json.PHP_EOL) === false) {
                $this->error(sprintf('Could not write flow definition export to [%s].', $file));

                return self::FAILURE;
            }

            $this->info(sprintf('Exported flow definition [%s] version [%d] to [%s].', $stored->name, $stored->version, $file));

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
