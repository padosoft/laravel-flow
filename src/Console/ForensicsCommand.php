<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use JsonException;
use Padosoft\LaravelFlow\Forensics\ForensicBundle;
use Padosoft\LaravelFlow\Forensics\ForensicExporter;
use Padosoft\LaravelFlow\Forensics\ForensicFinding;
use Padosoft\LaravelFlow\Forensics\ForensicVerifier;
use Padosoft\LaravelFlow\Forensics\RunNotExportableException;

/**
 * `flow:forensics` — export a run as evidence, or verify a bundle someone hands
 * you.
 *
 * Not to be confused with `flow:replay`, which RE-EXECUTES a run as a new one.
 * That is an operational tool: *do it again*. This one never executes anything:
 * *show me what happened, and prove the record was not edited*.
 *
 * @internal
 */
final class ForensicsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flow:forensics
        {runId? : Persisted run id to export}
        {--output= : Write the bundle to this path instead of stdout}
        {--raw : Skip the export-time redaction pass (routing can only be verified on a raw bundle)}
        {--verify= : Verify an existing bundle file instead of exporting}';

    /**
     * @var string
     */
    protected $description = 'Export a flow run as a content-addressed forensic bundle, or verify one.';

    public function handle(ForensicExporter $exporter, ForensicVerifier $verifier): int
    {
        $verify = $this->option('verify');

        if (is_string($verify) && $verify !== '') {
            return $this->verifyFile($verifier, $verify);
        }

        $runId = $this->argument('runId');

        if (! is_string($runId) || $runId === '') {
            $this->error('Pass a run id to export, or --verify=<path> to check a bundle.');

            return self::FAILURE;
        }

        try {
            $bundle = $exporter->export($runId, redact: $this->option('raw') !== true);
            $json = json_encode($bundle->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (RunNotExportableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (JsonException $e) {
            $this->error('The bundle could not be encoded: '.$e->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->line($json);

            return self::SUCCESS;
        }

        // Suppressed because the failure is reported below: a raw PHP warning on
        // top of the error line adds noise, not information.
        if (@file_put_contents($output, $json.PHP_EOL) === false) {
            $this->error("Could not write the bundle to [{$output}].");

            return self::FAILURE;
        }

        $this->info("Forensic bundle written to {$output}.");
        $this->line('  digest: '.$bundle->digest());

        if ($bundle->redacted) {
            $this->warn('  Redacted export: input routing cannot be verified on it. Use --raw inside a controlled environment when routing has to be proven.');
        }

        return self::SUCCESS;
    }

    private function verifyFile(ForensicVerifier $verifier, string $path): int
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->error("Could not read [{$path}].");

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $document */
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Not a valid bundle: '.$e->getMessage());

            return self::FAILURE;
        }

        $claimed = is_string($document['contentDigest'] ?? null) ? $document['contentDigest'] : null;
        $report = $verifier->verify(ForensicBundle::fromArray($document), $claimed);

        foreach ($report->findings as $finding) {
            $line = sprintf('  [%s] %s%s — %s',
                strtoupper($finding->status),
                $finding->check,
                $finding->nodeId !== null ? ' @'.$finding->nodeId : '',
                $finding->detail,
            );

            match ($finding->status) {
                ForensicFinding::FAILED => $this->error($line),
                ForensicFinding::UNVERIFIABLE => $this->warn($line),
                default => $this->line($line),
            };
        }

        $counts = $report->counts();

        if (! $report->intact()) {
            $this->error(sprintf('Bundle NOT intact: %d failed check(s).', $counts[ForensicFinding::FAILED]));

            return self::FAILURE;
        }

        // "Nothing failed" is not "everything was checked", and the summary says
        // so: a bundle whose payloads are masked can be perfectly un-tampered and
        // still prove very little.
        $this->info(sprintf(
            'Bundle intact: %d check(s) passed, %d could not be checked.',
            $counts[ForensicFinding::OK],
            $counts[ForensicFinding::UNVERIFIABLE],
        ));

        return self::SUCCESS;
    }
}
