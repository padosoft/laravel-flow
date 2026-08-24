<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Padosoft\LaravelFlow\Exceptions\FlowInputException;

/**
 * @api
 */
final readonly class FlowExecutionOptions
{
    public const MAX_IDENTIFIER_LENGTH = 255;

    public const MAX_RUN_ID_LENGTH = 36;

    public ?string $correlationId;

    public ?string $idempotencyKey;

    public ?string $replayedFromRunId;

    /**
     * The subject the run acts FOR (e.g. an IAM SubjectRef string like "user:42").
     * Set it when a run is started on behalf of a user — an agent-initiated run,
     * a delegated tool call — so downstream nodes (and audit) know who the work
     * is for WITHOUT smuggling identity through the run input (flow_runs.input
     * is persisted unredacted: never put tokens or identity there).
     */
    public ?string $subject;

    public function __construct(
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        ?string $replayedFromRunId = null,
        ?string $subject = null,
    ) {
        $this->correlationId = $this->normalize($correlationId, 'correlation id');
        $this->idempotencyKey = $this->normalize($idempotencyKey, 'idempotency key');
        $this->replayedFromRunId = $this->normalize(
            $replayedFromRunId,
            'replayed-from run id',
            self::MAX_RUN_ID_LENGTH,
        );
        $this->subject = $this->normalize($subject, 'subject');
    }

    public static function make(
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        ?string $replayedFromRunId = null,
        ?string $subject = null,
    ): self {
        return new self($correlationId, $idempotencyKey, $replayedFromRunId, $subject);
    }

    private function normalize(?string $value, string $field, int $maxLength = self::MAX_IDENTIFIER_LENGTH): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($this->characterLength($value) > $maxLength) {
            throw new FlowInputException(sprintf(
                'Flow execution %s may not exceed %d characters.',
                $field,
                $maxLength,
            ));
        }

        return $value;
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $characters = preg_match_all('/./us', $value);

        return $characters === false ? strlen($value) : $characters;
    }
}
