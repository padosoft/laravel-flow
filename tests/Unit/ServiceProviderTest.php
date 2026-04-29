<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

/**
 * Smoke test — verifies the service provider boots inside a Testbench
 * Laravel application.
 *
 * This is the v0.0.1 scaffold gate: as concrete bindings and tests
 * land during v4.0 development (workflow engine, dry-run, compensation,
 * approval gate), this file stays as the "package health" check.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_service_provider_boots_without_errors(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(LaravelFlowServiceProvider::class, $providers);
        $this->assertTrue($providers[LaravelFlowServiceProvider::class]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class];
    }
}
