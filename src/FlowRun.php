<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use DateTimeImmutable;

/**
 * Mutable value object representing one execution of a {@see FlowDefinition}.
 *
 * Lifecycle: pending -> running -> (succeeded | failed | compensated | aborted).
 * The engine mutates the run during execution and returns it to the caller.
 * It is intentionally not readonly — callers can introspect intermediate state
 * during long-running flows once v0.2 ships queued workers.
 *
 * @phpstan-type StepResultMap array<string, FlowStepResult>
 */
final class FlowRun
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMPENSATED = 'compensated';

    public const STATUS_ABORTED = 'aborted';

    public string $status = self::STATUS_PENDING;

    public ?string $failedStep = null;

    public bool $compensated = false;

    /**
     * @var array<string, FlowStepResult>
     */
    public array $stepResults = [];

    public ?DateTimeImmutable $finishedAt = null;

    public function __construct(
        public readonly string $id,
        public readonly string $definitionName,
        public readonly bool $dryRun,
        public readonly DateTimeImmutable $startedAt,
    ) {}

    public function recordStepResult(string $stepName, FlowStepResult $result): void
    {
        $this->stepResults[$stepName] = $result;
    }

    public function markRunning(): void
    {
        $this->status = self::STATUS_RUNNING;
    }

    public function markSucceeded(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->finishedAt = $now;
    }

    public function markFailed(string $stepName, DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failedStep = $stepName;
        $this->finishedAt = $now;
    }

    public function markCompensated(): void
    {
        $this->compensated = true;
    }

    public function markAborted(DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_ABORTED;
        $this->finishedAt = $now;
    }
}
