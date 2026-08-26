<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use JsonException;
use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Provenance\TaintAnalyzer;
use Padosoft\LaravelFlow\Provenance\TaintViolation;

/**
 * `flow:taint` — show which data in a stored definition is untrusted, and
 * along which path it got that way.
 *
 * The validator already refuses to publish a graph that hands untrusted
 * data to a port which refuses it, so this command is not the gate. It is
 * the thing you run BEFORE you hit the gate, and the thing you run when
 * you want to answer a question the gate never asks: *how much of this
 * graph is downstream of a model?* A graph can be perfectly valid and
 * still be one where nine nodes out of ten are carrying attacker-choosable
 * text — that is not a violation, but it is worth knowing.
 *
 * Exits non-zero when the definition has taint violations, so it can also
 * serve as a CI check on definitions that were stored before the analysis
 * existed.
 *
 * @internal
 */
final class TaintCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:taint
        {name : Stored definition name}
        {--version= : Definition version (defaults to the latest)}
        {--json : Emit the taint map as JSON instead of a table}';

    /**
     * @var string
     */
    protected $description = 'Show which ports of a stored flow definition carry untrusted data, and why.';

    public function handle(DefinitionRepository $definitions, TaintAnalyzer $analyzer, GraphSerializer $serializer): int
    {
        $name = (string) $this->argument('name');
        $version = $this->option('version');

        try {
            $stored = is_string($version) && $version !== ''
                ? $definitions->find($name, (int) $version)
                : $definitions->latest($name);
        } catch (DefinitionNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($stored === null) {
            $this->error("No stored definition named [{$name}].");

            return self::FAILURE;
        }

        // StoredDefinition holds the SERIALISED graph; the analysis needs
        // the object. A stored graph that no longer deserialises is a
        // definition nobody can run either, so say that plainly rather
        // than reporting "no taint" on a graph we could not read.
        try {
            $graph = $serializer->fromArray($stored->graph);
        } catch (InvalidGraphException $e) {
            $this->error("Stored definition [{$name}] v{$stored->version} does not deserialise: ".implode(' ', $e->violations()));

            return self::FAILURE;
        }

        $map = $analyzer->analyze($graph);
        $violations = $analyzer->violations($graph, $map);

        if ($this->option('json') === true) {
            return $this->emitJson($map->toArray(), $violations);
        }

        $untrusted = $map->untrustedPorts();

        if ($untrusted === []) {
            $this->info("No untrusted data in [{$name}] v{$stored->version}.");
        } else {
            $this->line("Untrusted ports in [{$name}] v{$stored->version}:");
            foreach ($untrusted as $port) {
                [$nodeId, $portKey] = explode('.', $port, 2);
                $path = $map->pathToOutput($nodeId, $portKey);
                $this->line("  {$port}   <- ".($path?->render() ?? ''));
            }
        }

        if ($violations === []) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('Taint violations:');

        foreach ($violations as $violation) {
            $this->line('  '.$violation->message());
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  list<TaintViolation>  $violations
     */
    private function emitJson(array $map, array $violations): int
    {
        try {
            $json = json_encode([
                'taint' => $map,
                'violations' => array_map(
                    static fn ($violation): array => $violation->toArray(),
                    $violations,
                ),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            $this->error('Could not encode the taint map: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line($json);

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
