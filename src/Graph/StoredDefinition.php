<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use DateTimeImmutable;

/**
 * Immutable read view of one persisted flow-definition version. `$graph`
 * is the raw {@see GraphSerializer::toArray()} envelope; round-trip it
 * through {@see GraphSerializer::fromArray()} to get an executable
 * {@see GraphDefinition}.
 *
 * @api
 */
final class StoredDefinition
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * @param  array<string, mixed>  $graph
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $version,
        public readonly string $status,
        public readonly array $graph,
        public readonly string $checksum,
        public readonly ?string $signature,
        public readonly ?DateTimeImmutable $publishedAt,
    ) {}
}
