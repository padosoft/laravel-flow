<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Provenance;

/**
 * The computed answer to "which data in this graph is untrusted, and why".
 *
 * Keyed by node id, then by port key, on both sides: `inputs` records which
 * of a node's INPUT ports received untrusted data (and along which path),
 * `outputs` which of its OUTPUT ports emit it. A port absent from a map is
 * trusted — the absence is the answer, so there is no third "unknown"
 * state to handle.
 *
 * @api
 */
final class TaintMap
{
    /**
     * @param  array<string, array<string, TaintPath>>  $inputs  nodeId => portKey => path
     * @param  array<string, array<string, TaintPath>>  $outputs  nodeId => portKey => path
     */
    public function __construct(
        public readonly array $inputs,
        public readonly array $outputs,
    ) {}

    public function inputIsUntrusted(string $nodeId, string $portKey): bool
    {
        return isset($this->inputs[$nodeId][$portKey]);
    }

    public function outputIsUntrusted(string $nodeId, string $portKey): bool
    {
        return isset($this->outputs[$nodeId][$portKey]);
    }

    public function pathToInput(string $nodeId, string $portKey): ?TaintPath
    {
        return $this->inputs[$nodeId][$portKey] ?? null;
    }

    public function pathToOutput(string $nodeId, string $portKey): ?TaintPath
    {
        return $this->outputs[$nodeId][$portKey] ?? null;
    }

    /**
     * Every untrusted port in the graph, rendered `node.port`, sorted — the
     * shape an operator reads and a test asserts against.
     *
     * @return list<string>
     */
    public function untrustedPorts(): array
    {
        $ports = [];

        foreach ($this->outputs as $nodeId => $byPort) {
            foreach (array_keys($byPort) as $portKey) {
                $ports[] = "{$nodeId}.{$portKey}";
            }
        }

        sort($ports);

        return $ports;
    }

    /**
     * @return array{inputs: array<string, array<string, list<string>>>, outputs: array<string, array<string, list<string>>>}
     */
    public function toArray(): array
    {
        $project = static function (array $side): array {
            $out = [];
            foreach ($side as $nodeId => $byPort) {
                foreach ($byPort as $portKey => $path) {
                    $out[$nodeId][$portKey] = $path->hops;
                }
            }

            return $out;
        };

        return [
            'inputs' => $project($this->inputs),
            'outputs' => $project($this->outputs),
        ];
    }
}
