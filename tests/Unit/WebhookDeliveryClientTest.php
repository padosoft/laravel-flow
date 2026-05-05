<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\WebhookDeliveryClient;
use Padosoft\LaravelFlow\WebhookDeliveryResult;

final class WebhookDeliveryClientTest extends TestCase
{
    public function test_deliver_returns_failure_when_url_is_empty(): void
    {
        $client = new WebhookDeliveryClient(
            transport: static fn (string $url, array $headers, string $body, int $timeout): array => ['status_code' => 200, 'body' => '', 'error' => ''],
        );

        $result = $client->deliver('', ['key' => 'value']);

        $this->assertInstanceOf(WebhookDeliveryResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertSame('webhook URL is empty', $result->error);
    }

    public function test_deliver_returns_failure_when_url_is_invalid(): void
    {
        $client = new WebhookDeliveryClient(
            transport: static fn (string $url, array $headers, string $body, int $timeout): array => ['status_code' => 200, 'body' => '', 'error' => ''],
        );

        $result = $client->deliver('not-a-url', ['key' => 'value']);

        $this->assertFalse($result->success);
        $this->assertSame('webhook URL is invalid', $result->error);
    }

    public function test_deliver_signs_request_when_secret_is_provided(): void
    {
        $captured = null;
        $client = new WebhookDeliveryClient(
            transport: static function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = $headers;

                return ['status_code' => 200, 'body' => 'ok', 'error' => ''];
            },
            timeoutSeconds: 2,
            secret: 'shared-secret',
        );

        $result = $client->deliver('https://example.test/hook', ['flow_run_id' => 'run-1']);

        $this->assertTrue($result->success);
        $this->assertNotNull($captured);
        $this->assertIsArray($captured);
        $signatureHeader = null;
        foreach ($captured as $line) {
            if (str_starts_with($line, 'X-Laravel-Flow-Signature: ')) {
                $signatureHeader = substr($line, strlen('X-Laravel-Flow-Signature: '));
                break;
            }
        }
        $this->assertNotNull($signatureHeader, 'Signature header should be present when secret is configured.');
        $this->assertMatchesRegularExpression('/^t=\d+,v1=[a-f0-9]{64}$/', (string) $signatureHeader);
    }

    public function test_deliver_omits_signature_when_secret_is_blank(): void
    {
        $captured = null;
        $client = new WebhookDeliveryClient(
            transport: static function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = $headers;

                return ['status_code' => 200, 'body' => 'ok', 'error' => ''];
            },
            timeoutSeconds: 2,
            secret: null,
        );

        $client->deliver('https://example.test/hook', []);

        $this->assertNotNull($captured);
        $this->assertIsArray($captured);
        foreach ($captured as $line) {
            $this->assertStringStartsNotWith('X-Laravel-Flow-Signature', $line);
        }
    }

    public function test_deliver_treats_non_2xx_as_failure(): void
    {
        $client = new WebhookDeliveryClient(
            transport: static fn (): array => ['status_code' => 500, 'body' => 'oops', 'error' => 'server error'],
            timeoutSeconds: 2,
        );

        $result = $client->deliver('https://example.test/hook', []);

        $this->assertFalse($result->success);
        $this->assertSame(500, $result->statusCode);
        $this->assertSame('server error', $result->error);
    }

    public function test_deliver_normalizes_empty_transport_error(): void
    {
        $client = new WebhookDeliveryClient(
            transport: static fn (): array => ['status_code' => 502, 'body' => '', 'error' => ''],
            timeoutSeconds: 2,
        );

        $result = $client->deliver('https://example.test/hook', []);

        $this->assertFalse($result->success);
        $this->assertSame('webhook delivery failed with non-success status code', $result->error);
    }
}
