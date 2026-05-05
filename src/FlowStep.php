<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * Readonly DTO describing a single step inside a {@see FlowDefinition}.
 *
 * Steps are immutable post-registration: the builder produces a fresh
 * FlowStep on every fluent mutation.
 *
 * @api
 */
final class FlowStep
{
    public function __construct(
        public readonly string $name,
        public readonly string $handlerFqcn,
        public readonly bool $supportsDryRun = false,
        public readonly ?string $compensatorFqcn = null,
    ) {}

    public function withDryRun(bool $supports = true): self
    {
        return new self(
            $this->name,
            $this->handlerFqcn,
            $supports,
            $this->compensatorFqcn,
        );
    }

    public function withCompensator(string $compensatorFqcn): self
    {
        return new self(
            $this->name,
            $this->handlerFqcn,
            $this->supportsDryRun,
            $compensatorFqcn,
        );
    }
}
