<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

/**
 * One thing the verifier checked, and how it went.
 *
 * Three states, not two, and the third is the point. **`unverifiable`** — a node
 * type the host no longer registers, a redacted payload, an upstream output that
 * was never recorded — is not a pass and not a failure: it is the verifier saying
 * *"I could not check this"*, out loud. Folding it into `ok` would let a bundle
 * that proves almost nothing come back clean, which is the failure mode a
 * forensic tool can least afford.
 *
 * @api
 */
final readonly class ForensicFinding
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const UNVERIFIABLE = 'unverifiable';

    public function __construct(
        public string $check,
        public string $status,
        public string $detail,
        public ?string $nodeId = null,
    ) {}

    public static function ok(string $check, string $detail, ?string $nodeId = null): self
    {
        return new self($check, self::OK, $detail, $nodeId);
    }

    public static function failed(string $check, string $detail, ?string $nodeId = null): self
    {
        return new self($check, self::FAILED, $detail, $nodeId);
    }

    public static function unverifiable(string $check, string $detail, ?string $nodeId = null): self
    {
        return new self($check, self::UNVERIFIABLE, $detail, $nodeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'check' => $this->check,
            'status' => $this->status,
            'detail' => $this->detail,
            'node_id' => $this->nodeId,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
