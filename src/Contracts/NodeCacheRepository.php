<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Contracts;

use DateTimeInterface;
use Padosoft\LaravelFlow\Models\FlowNodeCacheRecord;

/**
 * Persistence for the content-hash node cache. The `outputs`/`business_impact`
 * handed to put() are ALREADY redaction-gated by the caller (the node cache
 * service), so this repository is a plain persister — it does not redact again.
 *
 * @api
 */
interface NodeCacheRepository
{
    /**
     * The live (non-expired) cache row for `$contentHash`, or null on a miss.
     */
    public function find(string $contentHash, DateTimeInterface $now): ?FlowNodeCacheRecord;

    /**
     * Upsert a cache entry keyed by its content hash.
     *
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public function put(string $contentHash, string $nodeType, array $outputs, ?array $businessImpact, ?DateTimeInterface $expiresAt): void;
}
