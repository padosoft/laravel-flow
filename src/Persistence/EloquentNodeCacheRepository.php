<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Persistence;

use DateTimeInterface;
use Padosoft\LaravelFlow\Contracts\NodeCacheRepository;
use Padosoft\LaravelFlow\Executor\NodeCacheHit;
use Padosoft\LaravelFlow\Models\FlowNodeCacheRecord;

/**
 * @internal
 */
final class EloquentNodeCacheRepository implements NodeCacheRepository
{
    public function __construct(
        private readonly ?string $connection,
    ) {}

    public function find(string $contentHash, DateTimeInterface $now): ?NodeCacheHit
    {
        $row = $this->newModel()->newQuery()
            ->where('content_hash', $contentHash)
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->first();

        if ($row === null) {
            return null;
        }

        return new NodeCacheHit(
            is_array($row->outputs) ? $row->outputs : [],
            is_array($row->business_impact) ? $row->business_impact : null,
        );
    }

    public function put(string $contentHash, string $nodeType, array $outputs, ?array $businessImpact, ?DateTimeInterface $expiresAt): void
    {
        $model = $this->newModel();
        $timestamp = $model->freshTimestamp();

        $model->newQuery()->upsert(
            [[
                'content_hash' => $contentHash,
                'node_type' => $nodeType,
                'outputs' => json_encode($outputs, JSON_THROW_ON_ERROR),
                'business_impact' => $businessImpact !== null ? json_encode($businessImpact, JSON_THROW_ON_ERROR) : null,
                'expires_at' => $expiresAt,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]],
            ['content_hash'],
            ['node_type', 'outputs', 'business_impact', 'expires_at', 'updated_at'],
        );
    }

    private function newModel(): FlowNodeCacheRecord
    {
        return (new FlowNodeCacheRecord)->setConnection($this->connection);
    }
}
