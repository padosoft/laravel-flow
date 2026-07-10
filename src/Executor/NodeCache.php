<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Executor;

use Closure;
use DateTimeImmutable;
use Padosoft\LaravelFlow\Contracts\NodeCacheRepository;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Persistence\PersistencePayloadRedaction;

/**
 * Content-hash node cache: serves a `#[Cacheable]` node's outputs on a hit and
 * stores them on a miss. A cache WRITE goes through the SAME {@see PayloadRedactor}
 * as every other persisted payload — no carve-out from the redaction gate — and
 * is SKIPPED entirely whenever redaction would alter the value (rather than
 * persisting a redacted placeholder as reusable data). Net effect: a cache HIT
 * can never return a value that diverges from what a MISS would have produced,
 * so a node whose output contains a redacted-list key simply never caches.
 *
 * @api
 */
final class NodeCache
{
    private readonly ContentHasher $hasher;

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly NodeCacheRepository $repository,
        private readonly PayloadRedactor $redactor,
        private readonly Closure $clock,
    ) {
        $this->hasher = new ContentHasher;
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $config
     */
    public function hash(string $type, array $inputs, array $config): string
    {
        return $this->hasher->hash($type, $inputs, $config);
    }

    public function get(string $contentHash): ?NodeCacheHit
    {
        $row = $this->repository->find($contentHash, ($this->clock)());

        if ($row === null) {
            return null;
        }

        return new NodeCacheHit(
            is_array($row->outputs) ? $row->outputs : [],
            is_array($row->business_impact) ? $row->business_impact : null,
        );
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public function put(string $contentHash, string $nodeType, array $outputs, ?array $businessImpact, ?int $ttlSeconds): void
    {
        $raw = ['outputs' => $outputs];

        if ($businessImpact !== null) {
            $raw['business_impact'] = $businessImpact;
        }

        $redacted = PersistencePayloadRedaction::redactFields($this->redactor, $raw, ['outputs', 'business_impact']);

        // Redaction changed something -> this output carries a redacted-list key.
        // Do NOT cache it: a later hit would otherwise return the placeholder,
        // diverging from what a fresh (uncached) run would produce.
        if ($redacted !== $raw) {
            return;
        }

        $expiresAt = $ttlSeconds !== null
            ? ($this->clock)()->modify("+{$ttlSeconds} seconds")
            : null;

        $this->repository->put($contentHash, $nodeType, $outputs, $businessImpact, $expiresAt);
    }
}
