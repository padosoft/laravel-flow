<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Padosoft\LaravelFlow\Models\FlowRunRecord;

interface RunRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): FlowRunRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $runId, array $attributes): FlowRunRecord;

    public function find(string $runId): ?FlowRunRecord;

    public function findByIdempotencyKey(string $idempotencyKey): ?FlowRunRecord;
}
