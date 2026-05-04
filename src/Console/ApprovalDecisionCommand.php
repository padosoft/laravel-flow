<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use JsonException;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Throwable;

abstract class ApprovalDecisionCommand extends Command
{
    /**
     * @return array<string, mixed>
     */
    protected function jsonOption(string $option): array
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            throw new JsonException(sprintf('Option --%s must contain JSON.', $option));
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonException(sprintf('Approval option --%s must contain valid JSON object or array.', $option), previous: $e);
        }

        if (! is_array($decoded)) {
            throw new JsonException(sprintf('Approval option --%s must contain valid JSON object or array.', $option));
        }

        return $decoded;
    }

    protected function handleDecision(callable $decision): int
    {
        $token = trim((string) $this->argument('token'));

        if ($token === '') {
            $this->error('Approval token must not be blank.');

            return self::FAILURE;
        }

        try {
            $payload = $this->jsonOption('payload');
            $actor = $this->jsonOption('actor');
        } catch (JsonException $e) {
            $this->error($e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line($this->verboseFailureDetails($e));
            }

            return self::FAILURE;
        }

        try {
            /** @var FlowEngine $flow */
            $flow = $this->getLaravel()->make(FlowEngine::class);
            $run = $decision($flow, $token, $payload, $actor);
        } catch (FlowExecutionException $e) {
            $this->error($e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line($this->verboseFailureDetails($e));
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error(sprintf('Flow approval command [%s] failed unexpectedly.', $this->getName() ?? 'flow:approval'));

            if ($this->getOutput()->isVerbose()) {
                $this->line($this->verboseFailureDetails($e));
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s flow run [%s] with status [%s].',
            $this->resultVerb(),
            $run->id,
            $run->status,
        ));

        return self::SUCCESS;
    }

    abstract protected function resultVerb(): string;

    private function verboseFailureDetails(Throwable $exception): string
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof Throwable) {
            return sprintf('%s: %s', $previous::class, $previous->getMessage());
        }

        return sprintf('%s: %s', $exception::class, $exception->getMessage());
    }
}
