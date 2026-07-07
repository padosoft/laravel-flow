<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\FlowStepResult;
use Throwable;

/**
 * Readonly DTO summarising one node execution. Factory semantics mirror
 * {@see FlowStepResult} 1:1, so every v1 step outcome (success, failure,
 * dry-run skip, pause) has an exact node-result counterpart.
 *
 * @api
 */
final class NodeResult
{
    /**
     * @param  array<string, mixed>  $outputs  keyed by output port key
     * @param  array<string, mixed>|null  $businessImpact
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $outputs,
        public readonly ?Throwable $error,
        public readonly ?array $businessImpact,
        public readonly bool $dryRunSkipped,
        public readonly bool $paused,
    ) {}

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function success(array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, false);
    }

    public static function failed(Throwable $error): self
    {
        return new self(false, [], $error, null, false, false);
    }

    public static function dryRunSkipped(): self
    {
        return new self(true, [], null, null, true, false);
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function paused(array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, true);
    }
}
