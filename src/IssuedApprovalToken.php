<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use DateTimeImmutable;

/**
 * @api
 */
final class IssuedApprovalToken
{
    public function __construct(
        public readonly string $approvalId,
        public readonly string $runId,
        public readonly string $stepName,
        public readonly string $plainTextToken,
        public readonly string $tokenHash,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
