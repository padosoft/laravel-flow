<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;

final class EloquentFlowStore implements FlowStore
{
    public function __construct(
        private readonly ?string $connection,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function runs(): RunRepository
    {
        return new EloquentRunRepository($this->connection, $this->redactor);
    }

    public function steps(): StepRunRepository
    {
        return new EloquentStepRunRepository($this->connection, $this->redactor);
    }

    public function audit(): AuditRepository
    {
        return new EloquentAuditRepository($this->connection, $this->redactor);
    }

    public function transaction(callable $callback): mixed
    {
        return DB::connection($this->connection)->transaction(
            static fn (mixed $_connection): mixed => $callback(),
        );
    }
}
