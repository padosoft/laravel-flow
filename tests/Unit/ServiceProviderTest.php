<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\ApprovalTokenManager;
use Padosoft\LaravelFlow\Contracts\ApprovalDecisionRepository;
use Padosoft\LaravelFlow\Contracts\ApprovalRepository;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\ConditionalRunRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RedactorAwareApprovalRepository;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Dashboard\Authorization\AllowAllAuthorizer;
use Padosoft\LaravelFlow\Dashboard\Authorization\DashboardActionAuthorizer;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Persistence\EloquentWebhookOutboxRepository;
use Padosoft\LaravelFlow\WebhookDeliveryClient;

/**
 * Smoke coverage for service-provider auto-discovery and package bindings.
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

    public function test_register_and_boot_complete_without_throwing(): void
    {
        $provider = new LaravelFlowServiceProvider($this->app);

        $provider->register();
        $provider->boot();

        $this->assertTrue(
            $this->app->providerIsLoaded(LaravelFlowServiceProvider::class),
            'Testbench should have registered the provider during setUp().',
        );
    }

    public function test_persistence_and_approval_services_are_bound(): void
    {
        $this->assertTrue($this->app->bound(FlowStore::class));
        $this->assertTrue($this->app->bound(RunRepository::class));
        $this->assertTrue($this->app->bound(StepRunRepository::class));
        $this->assertTrue($this->app->bound(AuditRepository::class));
        $this->assertTrue($this->app->bound(ApprovalRepository::class));
        $this->assertTrue($this->app->bound(EloquentWebhookOutboxRepository::class));
        $this->assertTrue($this->app->bound(PayloadRedactor::class));
        $this->assertTrue($this->app->bound(ApprovalTokenManager::class));
        $this->assertTrue($this->app->bound(WebhookDeliveryClient::class));
        $this->assertFalse($this->app->bound(ConditionalRunRepository::class));
        $this->assertFalse($this->app->bound(ApprovalDecisionRepository::class));
        $this->assertInstanceOf(ConditionalRunRepository::class, $this->app->make(FlowStore::class)->runs());
        $this->assertInstanceOf(ApprovalDecisionRepository::class, $this->app->make(ApprovalRepository::class));
        $this->assertInstanceOf(RedactorAwareApprovalRepository::class, $this->app->make(ApprovalRepository::class));
    }

    public function test_parallel_compensation_unknown_driver_falls_back_to_engine_resolution(): void
    {
        $this->app['config']->set('laravel-flow.compensation_strategy', 'parallel');
        $this->app['config']->set('laravel-flow.compensation_parallel_driver', 'missing-driver');
        $this->app->forgetInstance(FlowEngine::class);

        $this->assertInstanceOf(FlowEngine::class, $this->app->make(FlowEngine::class));
    }

    public function test_config_and_migrations_are_publishable(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $configPublishes = ServiceProvider::pathsToPublish(
            LaravelFlowServiceProvider::class,
            'laravel-flow-config',
        );
        $migrationPublishes = ServiceProvider::pathsToPublish(
            LaravelFlowServiceProvider::class,
            'laravel-flow-migrations',
        );

        $configSources = array_map('realpath', array_keys($configPublishes));
        $migrationSources = array_map('realpath', array_keys($migrationPublishes));

        $this->assertContains(realpath($packageRoot.'/config/laravel-flow.php'), $configSources);
        $this->assertContains(
            realpath($packageRoot.'/database/migrations/2026_05_02_000001_create_laravel_flow_tables.php'),
            $migrationSources,
        );
        $this->assertContains(
            realpath($packageRoot.'/database/migrations/2026_05_04_000002_add_replay_lineage_to_laravel_flow_runs.php'),
            $migrationSources,
        );
        $this->assertContains(
            realpath($packageRoot.'/database/migrations/2026_05_04_000003_create_laravel_flow_approval_and_webhook_tables.php'),
            $migrationSources,
        );
        $this->assertContains(
            realpath($packageRoot.'/database/migrations/2026_05_04_000004_add_previous_token_hash_to_flow_approvals.php'),
            $migrationSources,
        );
    }

    public function test_deliver_webhook_outbox_command_is_registered(): void
    {
        $this->artisan('flow:deliver-webhooks')
            ->expectsOutputToContain('Enable laravel-flow.webhook.enabled before delivering webhook outbox rows.')
            ->assertExitCode(1);
    }

    public function test_dashboard_read_model_and_authorizer_are_bound(): void
    {
        $this->assertTrue($this->app->bound(FlowDashboardReadModel::class));
        $this->assertTrue($this->app->bound(DashboardActionAuthorizer::class));
        $this->assertInstanceOf(FlowDashboardReadModel::class, $this->app->make(FlowDashboardReadModel::class));
        $this->assertInstanceOf(AllowAllAuthorizer::class, $this->app->make(DashboardActionAuthorizer::class));
    }
}
