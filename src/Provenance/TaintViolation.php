<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Provenance;

/**
 * One wiring in the graph that would hand untrusted data to a port which
 * declared it will not accept any: a model completion reaching a shell
 * command, a scraped page reaching a tool's argument map.
 *
 * @api
 */
final class TaintViolation
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $portKey,
        public readonly TaintPath $path,
    ) {}

    /**
     * The message a graph author reads. It names the sink, the origin, and
     * the whole route between them, because the fix is almost never at the
     * sink — it is somewhere along the path.
     */
    public function message(): string
    {
        return sprintf(
            'Input [%s] on node [%s] requires trusted data but receives untrusted data originating at [%s] (path: %s).',
            $this->portKey,
            $this->nodeId,
            $this->path->origin(),
            $this->path->render(),
        );
    }

    /**
     * @return array{node_id: string, port_key: string, origin: string, path: list<string>}
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'port_key' => $this->portKey,
            'origin' => $this->path->origin(),
            'path' => $this->path->hops,
        ];
    }
}
