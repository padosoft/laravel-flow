<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphTransfer;
use Throwable;

/**
 * @internal
 */
final class ImportFlowDefinitionCommand extends Command
{
    private const FORMAT_NATIVE = 'laravel-flow';

    /**
     * @var string
     */
    protected $signature = 'flow:import
        {file : Path to the JSON graph file to import}
        {--name= : Draft name; falls back to a "metadata.name" (or top-level "name") key in the JSON}
        {--publish : Publish the imported draft immediately}
        {--format=laravel-flow : Import format: "laravel-flow" (native export)}';

    /**
     * @var string
     */
    protected $description = 'Import a JSON graph as a new draft flow definition.';

    public function handle(GraphTransfer $transfer, DefinitionRepository $definitions): int
    {
        $format = (string) $this->option('format');

        if ($format !== self::FORMAT_NATIVE) {
            $this->error(sprintf('Unsupported --format [%s]; expected "%s".', $format, self::FORMAT_NATIVE));

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        $json = @file_get_contents($path);

        if ($json === false) {
            $this->error(sprintf('Flow definition file [%s] could not be read.', $path));

            return self::FAILURE;
        }

        $name = $this->resolveName($json);

        if ($name === null) {
            $this->error('flow:import requires --name or a "metadata.name" (or top-level "name") key in the imported JSON.');

            return self::FAILURE;
        }

        try {
            $stored = $transfer->importDraft($json, $name);
        } catch (InvalidGraphException $e) {
            $this->reportViolations('Flow definition import failed with violations:', $e->violations());

            return self::FAILURE;
        } catch (DefinitionSignatureException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Flow definition import failed unexpectedly.');

            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('publish')) {
            try {
                $stored = $definitions->publish($stored->name, $stored->version);
            } catch (InvalidGraphException $e) {
                $this->reportViolations(
                    sprintf('Flow definition [%s] version [%d] failed semantic validation on publish:', $stored->name, $stored->version),
                    $e->violations(),
                );

                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error(sprintf('Flow definition [%s] version [%d] could not be published.', $stored->name, $stored->version));

                if ($this->getOutput()->isVerbose()) {
                    $this->line($e->getMessage());
                }

                return self::FAILURE;
            }
        }

        $this->info(sprintf('Imported flow definition [%s] version [%d] (%s).', $stored->name, $stored->version, $stored->status));

        return self::SUCCESS;
    }

    private function resolveName(string $json): ?string
    {
        $option = $this->option('name');

        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        $metadata = $decoded['metadata'] ?? null;
        $candidate = (is_array($metadata) ? ($metadata['name'] ?? null) : null) ?? ($decoded['name'] ?? null);

        return is_string($candidate) && trim($candidate) !== '' ? trim($candidate) : null;
    }

    /**
     * @param  list<string>  $violations
     */
    private function reportViolations(string $heading, array $violations): void
    {
        $this->error($heading);

        foreach ($violations as $violation) {
            $this->line('  - '.$violation);
        }
    }
}
