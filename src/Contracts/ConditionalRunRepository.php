<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use Padosoft\LaravelFlow\Models\FlowRunRecord;

/**
 * Optional repository extension for compare-and-set run transitions.
 */
interface ConditionalRunRepository
{
    /**
     * Update mutable runtime fields only when the run still has the expected status.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateWhereStatus(string $runId, string $expectedStatus, array $attributes): ?FlowRunRecord;
}
