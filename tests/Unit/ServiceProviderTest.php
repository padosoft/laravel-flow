<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

/**
 * Smoke coverage for the v0.0.1 scaffold.
 *
 * The package currently ships an empty no-op `LaravelFlowServiceProvider`;
 * real bindings land during v4.0 development. This test pins the
 * scaffold's two non-negotiable contracts so a future regression in
 * the auto-discovery wiring fails CI immediately:
 *
 *   1. The provider is a true `Illuminate\Support\ServiceProvider`
 *      subclass (auto-discovery requires this).
 *   2. Loading the provider into a fresh Testbench app does not throw
 *      — both `register()` and `boot()` complete cleanly even though
 *      they are no-ops.
 *
 * When v4.0 brings real bindings, replace these assertions with
 * coverage of the actual public surface.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class];
    }

    public function test_service_provider_is_a_laravel_service_provider_subclass(): void
    {
        $reflection = new \ReflectionClass(LaravelFlowServiceProvider::class);

        $this->assertTrue(
            $reflection->isSubclassOf(ServiceProvider::class),
            'LaravelFlowServiceProvider must extend Illuminate\Support\ServiceProvider for Laravel package auto-discovery to wire it.',
        );
    }

    public function test_service_provider_boots_without_throwing(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LaravelFlowServiceProvider::class));
    }
}
