<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

/**
 * @api
 */
final class WebhookDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $statusCode,
        public readonly string $body,
        public readonly string $error,
        public readonly string $url,
    ) {}

    public static function success(int $statusCode, string $body, string $url): self
    {
        return new self(true, $statusCode, $body, '', $url);
    }

    public static function failure(int $statusCode, string $body, string $error, string $url): self
    {
        return new self(false, $statusCode, $body, $error, $url);
    }
}
