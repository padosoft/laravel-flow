<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

use DateTimeInterface;
use JsonException;

/**
 * Everything that determined one run, in one content-addressed document.
 *
 * The question this exists to answer is asked after an incident and it is
 * always the same one: *what did this run actually see, and can I show that to
 * someone six months from now?* A dashboard answers it while the rows are
 * there; it does not answer it to an auditor, a regulator, or a customer's
 * lawyer, none of whom will take "trust our database" for it.
 *
 * So the bundle is **self-contained** — the graph, the run, every node in
 * sequence with its inputs and outputs — and **content-addressed**: a digest
 * over its own canonical form, so an edited copy stops matching itself.
 *
 * What it is NOT, said here because someone will assume otherwise: it does not
 * re-run the model, and it is not a claim that the recorded outputs are
 * *correct*. It is a claim about what was **recorded**, whether that record is
 * **internally consistent**, and whether it has been **altered since export**.
 * The non-deterministic part of a flow is the node bodies; the deterministic
 * part is the routing between them, and that is the part
 * {@see ForensicVerifier} replays.
 *
 * @api
 */
final readonly class ForensicBundle
{
    public const FORMAT = 'padosoft-flow-forensics';

    public const SPEC_VERSION = '1.0';

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $nodes
     */
    public function __construct(
        public array $run,
        public array $definition,
        public array $nodes,
        public bool $redacted,
        public ?DateTimeInterface $exportedAt = null,
    ) {}

    /**
     * The document, without its own digest (which cannot contain itself).
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return [
            'format' => self::FORMAT,
            'specVersion' => self::SPEC_VERSION,
            'redacted' => $this->redacted,
            'run' => $this->run,
            'definition' => $this->definition,
            'nodes' => $this->nodes,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function toArray(): array
    {
        return [
            ...$this->body(),
            // Outside the digested body on purpose: two exports of the same
            // finished run must compare equal, and a timestamp would make every
            // export a different document.
            'exportedAt' => $this->exportedAt?->format(DateTimeInterface::ATOM),
            'contentDigest' => $this->digest(),
        ];
    }

    /**
     * @throws JsonException
     */
    public function digest(): string
    {
        return 'sha256:'.hash('sha256', ForensicCanonicalJson::encode($this->body()));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function fromArray(array $document): self
    {
        /** @var array<string, mixed> $run */
        $run = is_array($document['run'] ?? null) ? $document['run'] : [];
        /** @var array<string, mixed> $definition */
        $definition = is_array($document['definition'] ?? null) ? $document['definition'] : [];
        /** @var list<array<string, mixed>> $nodes */
        $nodes = is_array($document['nodes'] ?? null) ? array_values(array_filter($document['nodes'], 'is_array')) : [];

        return new self(
            run: $run,
            definition: $definition,
            nodes: $nodes,
            redacted: ($document['redacted'] ?? false) === true,
        );
    }
}
