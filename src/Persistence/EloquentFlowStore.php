<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareFlowStore;
use Padosoft\LaravelFlow\Contracts\RunNodeRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;

/**
 * @internal
 */
final class EloquentFlowStore implements FlowStore, RedactorAwareFlowStore
{
    // Built once per store: the run-node repository memoises its schema probes
    // (`hasColumn`), and a fresh instance per runNodes() call would repeat that
    // metadata query on every coordinator pass. The store is a container
    // singleton, so this makes the probe once-per-process.
    private ?EloquentRunNodeRepository $runNodes = null;

    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function runs(): RunRepository
    {
        return new EloquentRunRepository($this->connection, $this->redactor);
    }

    public function runNodes(): RunNodeRepository
    {
        return $this->runNodes ??= new EloquentRunNodeRepository($this->connection, $this->redactor);
    }

    public function audit(): AuditRepository
    {
        return new EloquentAuditRepository($this->connection, $this->redactor);
    }

    public function withPayloadRedactor(PayloadRedactor $redactor): FlowStore
    {
        return new self($this->connection, $redactor);
    }

    public function transaction(callable $callback): mixed
    {
        return DB::connection($this->connection)->transaction(
            static fn (mixed $_connection): mixed => $callback(),
        );
    }
}
