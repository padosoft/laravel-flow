<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Provenance;

use Padosoft\LaravelFlow\Node\PortProvenance;

/**
 * Why a given output port is untrusted: the chain of ports the taint
 * travelled along, from the source that declared itself untrusted to the
 * port being explained.
 *
 * This exists because "your graph is rejected" is a useless error message.
 * The author wired an LLM into a summariser into a formatter into a tool
 * call; being told the tool call is tainted tells them nothing they can
 * act on. Being told *the model output three hops back is the source*
 * tells them exactly where to put the sanitizer.
 *
 * Hops are ordered source-first and rendered `node.port`. The first hop is
 * always the port that declared {@see PortProvenance::Untrusted};
 * the last is the port this path explains.
 *
 * @api
 */
final class TaintPath
{
    /**
     * @param  list<string>  $hops  ordered `nodeId.portKey`, source first
     */
    public function __construct(public readonly array $hops) {}

    public static function source(string $nodeId, string $portKey): self
    {
        return new self(["{$nodeId}.{$portKey}"]);
    }

    public function then(string $nodeId, string $portKey): self
    {
        return new self([...$this->hops, "{$nodeId}.{$portKey}"]);
    }

    /**
     * The port that originated the taint — the one an author must either
     * sanitize downstream of, or stop wiring into a sink.
     */
    public function origin(): string
    {
        return $this->hops[0] ?? '';
    }

    public function render(): string
    {
        return implode(' -> ', $this->hops);
    }
}
