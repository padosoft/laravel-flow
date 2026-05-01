<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Throwable;

/**
 * Readonly DTO summarising the outcome of one step execution.
 *
 * @phpstan-type Impact array<string, mixed>|null
 */
final class FlowStepResult
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $businessImpact
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $output = [],
        public readonly ?Throwable $error = null,
        public readonly ?array $businessImpact = null,
        public readonly bool $dryRunSkipped = false,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function success(array $output = [], ?array $businessImpact = null): self
    {
        return new self(true, $output, null, $businessImpact, false);
    }

    public static function failed(Throwable $error): self
    {
        return new self(false, [], $error, null, false);
    }

    public static function dryRunSkipped(): self
    {
        return new self(true, [], null, null, true);
    }
}
