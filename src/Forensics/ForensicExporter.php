<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

use DateTimeImmutable;
use DateTimeInterface;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;

/**
 * Assembles a {@see ForensicBundle} from a persisted run.
 *
 * Everything comes from what was already recorded — the run row, the graph
 * snapshot the run itself stored, and the node rows in sequence. Nothing is
 * re-executed and nothing is inferred: an exporter that filled a gap with a
 * guess would produce a document that looks like evidence and is not.
 *
 * **Redaction is applied again on export, by default.** The rows were already
 * written under whatever redaction policy the host chose; this pass exists
 * because an export *leaves the system* — it goes into a ticket, an email, a
 * regulator's inbox — and the bar for a document that travels is not the bar
 * for a row in your own database. `--raw` skips it, and the bundle records
 * which of the two it is, so nobody has to guess later whether a missing value
 * was masked or never there.
 *
 * @api
 */
final class ForensicExporter
{
    public function __construct(private readonly ?PayloadRedactor $redactor = null) {}

    /**
     * @throws RunNotExportableException when the run has no persisted record
     */
    public function export(string $runId, bool $redact = true, ?DateTimeInterface $exportedAt = null): ForensicBundle
    {
        $run = FlowRunRecord::query()->find($runId);

        if ($run === null) {
            throw new RunNotExportableException("Flow run [{$runId}] has no persisted record to export.");
        }

        $applyRedaction = $redact && $this->redactor !== null;

        /** @var list<FlowRunNodeRecord> $rows */
        $rows = array_values(
            FlowRunNodeRecord::query()
                ->where('run_id', $runId)
                // `sequence` is the execution order and the only ordering a
                // forensic record may use; `id` would be insertion order, which
                // is the same thing until it isn't (retries, queued nodes).
                ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
                ->all(),
        );

        return new ForensicBundle(
            run: [
                'id' => $run->id,
                'flow' => $run->definition_name,
                'status' => $run->status,
                'dry_run' => (bool) $run->dry_run,
                'engine' => $run->engine,
                'subject' => $run->subject,
                'correlation_id' => $run->correlation_id,
                'replayed_from_run_id' => $run->replayed_from_run_id,
                'input' => $this->payload($run->input, $applyRedaction),
                'output' => $this->payload($run->output, $applyRedaction),
                'business_impact' => $run->business_impact,
                'failed_step' => $run->failed_step,
                'compensated' => (bool) $run->compensated,
                'compensation_status' => $run->compensation_status,
                'nodes_total' => $run->nodes_total,
                'nodes_completed' => $run->nodes_completed,
                'nodes_failed' => $run->nodes_failed,
                'duration_ms' => $run->duration_ms,
                'started_at' => $run->started_at?->format(DateTimeInterface::ATOM),
                'finished_at' => $run->finished_at?->format(DateTimeInterface::ATOM),
            ],
            definition: [
                'name' => $run->definition_name,
                'version' => $run->definition_version,
                'checksum' => $run->definition_checksum,
                // The graph the run ITSELF snapshotted, not the one currently
                // stored under that name and version. A definition can be
                // re-signed, re-imported, or simply edited by someone with
                // database access; the snapshot is what actually ran.
                'graph' => $run->graph,
            ],
            nodes: array_map(fn (FlowRunNodeRecord $row): array => [
                'sequence' => $row->sequence,
                'node_id' => $row->node_id,
                'node_type' => $row->node_type,
                'handler' => $row->handler,
                'status' => $row->status,
                'attempts' => $row->attempts,
                'inputs' => $this->payload($row->inputs, $applyRedaction),
                'outputs' => $this->payload($row->outputs, $applyRedaction),
                'business_impact' => $row->business_impact,
                'error_class' => $row->error_class,
                'error_message' => $row->error_message,
                'dry_run_skipped' => (bool) $row->dry_run_skipped,
                'cache_hit' => $row->cache_hit,
                'duration_ms' => $row->duration_ms,
                'started_at' => $row->started_at?->format(DateTimeInterface::ATOM),
                'finished_at' => $row->finished_at?->format(DateTimeInterface::ATOM),
            ], $rows),
            redacted: $applyRedaction,
            exportedAt: $exportedAt ?? new DateTimeImmutable,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function payload(?array $payload, bool $redact): ?array
    {
        if ($payload === null || ! $redact || $this->redactor === null) {
            return $payload;
        }

        return $this->redactor->redact($payload);
    }
}
