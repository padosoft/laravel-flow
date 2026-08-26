<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Forensics;

/**
 * What the verifier could and could not establish about a bundle.
 *
 * `intact()` is deliberately narrow: it means **nothing failed**. It does not
 * mean everything was checked — `unverifiable` findings are reported separately
 * and counted, because a bundle whose payloads are redacted or whose node types
 * are no longer registered can be perfectly un-tampered and still prove very
 * little. Conflating the two would turn "I could not check" into "it's fine",
 * which is the one mistake a forensic tool must not make.
 *
 * @api
 */
final readonly class ForensicReport
{
    /**
     * @param  list<ForensicFinding>  $findings
     */
    public function __construct(public array $findings) {}

    public function intact(): bool
    {
        return $this->ofStatus(ForensicFinding::FAILED) === [];
    }

    /**
     * @return list<ForensicFinding>
     */
    public function ofStatus(string $status): array
    {
        return array_values(array_filter($this->findings, static fn (ForensicFinding $f): bool => $f->status === $status));
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            ForensicFinding::OK => count($this->ofStatus(ForensicFinding::OK)),
            ForensicFinding::FAILED => count($this->ofStatus(ForensicFinding::FAILED)),
            ForensicFinding::UNVERIFIABLE => count($this->ofStatus(ForensicFinding::UNVERIFIABLE)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intact' => $this->intact(),
            'counts' => $this->counts(),
            'findings' => array_map(static fn (ForensicFinding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
