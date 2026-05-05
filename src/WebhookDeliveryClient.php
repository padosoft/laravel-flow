<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use JsonException;
use Throwable;

/**
 * @api
 */
final class WebhookDeliveryClient
{
    /** @var callable(string, list<string>, string, int): array{status_code: int, body: string, error: string} */
    private $transport;

    private const DEFAULT_SIGNATURE_HEADER = 'X-Laravel-Flow-Signature';

    private const DEFAULT_TIMEOUT_SECONDS = 5;

    /**
     * @param  null|callable(string $url, list<string> $headers, string $body, int $timeout): array{status_code: int, body: string, error: string}  $transport
     */
    public function __construct(
        ?callable $transport = null,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly ?string $secret = null,
    ) {
        /** @var callable(string $url, list<string> $headers, string $body, int $timeout): array{status_code: int, body: string, error: string} $resolvedTransport */
        $resolvedTransport = $transport ?? $this->defaultTransport(...);
        $this->transport = $resolvedTransport;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliver(string $url, array $payload): WebhookDeliveryResult
    {
        if (trim($url) === '') {
            return WebhookDeliveryResult::failure(0, '', 'webhook URL is empty', '');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return WebhookDeliveryResult::failure(0, '', 'webhook URL is invalid', $url);
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            return WebhookDeliveryResult::failure(0, '', 'webhook payload could not be serialized', $url);
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (($this->secret ?? '') !== '') {
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

            $headers[self::DEFAULT_SIGNATURE_HEADER] = sprintf('t=%s,v1=%s', $timestamp, $signature);
        }

        $headerLines = array_map(
            static fn (string $name, string $value): string => sprintf('%s: %s', $name, $value),
            array_keys($headers),
            array_values($headers),
        );

        try {
            /** @var array{status_code: int, body: string, error: string} $response */
            $response = ($this->transport)($url, $headerLines, $body, $this->timeoutSeconds > 0 ? $this->timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS);
        } catch (Throwable $exception) {
            return WebhookDeliveryResult::failure(0, '', $exception->getMessage(), $url);
        }

        $statusCode = (int) ($response['status_code'] ?? 0);
        $responseBody = (string) ($response['body'] ?? '');
        $error = (string) ($response['error'] ?? '');

        if ($statusCode >= 200 && $statusCode <= 299) {
            return WebhookDeliveryResult::success($statusCode, $responseBody, $url);
        }

        return WebhookDeliveryResult::failure($statusCode, $responseBody, $this->normalizeError($error), $url);
    }

    private function normalizeError(string $error): string
    {
        $trimmed = trim($error);

        return $trimmed === '' ? 'webhook delivery failed with non-success status code' : $trimmed;
    }

    /**
     * @param  list<string>  $headers
     * @return array{status_code: int, body: string, error: string}
     */
    private function defaultTransport(string $url, array $headers, string $body, int $timeout): array
    {
        if ($timeout < 1) {
            throw new \RuntimeException('Webhook delivery timeout must be at least 1 second.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [...$headers, 'Content-Length: '.strlen($body)]),
                'timeout' => $timeout,
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $errorMessage = null;
        $http_response_header = [];
        // PHP populates $http_response_header in the local scope where file_get_contents() runs.
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $lastError = error_get_last();
            $errorMessage = $lastError['message'] ?? 'Failed to send webhook.';
            $statusCode = 0;
            $responseBody = '';
        } else {
            $statusCode = $this->statusCodeFromHeaders($http_response_header);
            $responseBody = (string) $response;
        }

        return [
            'status_code' => $statusCode,
            'body' => $responseBody,
            'error' => (string) $errorMessage,
        ];
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function statusCodeFromHeaders(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        foreach (array_reverse($headers) as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)\s+/i', $headerLine, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
