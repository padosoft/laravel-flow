<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlow\Console\PruneFlowRunsCommand;
use Padosoft\LaravelFlow\Console\ReplayFlowRunCommand;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Persistence\EloquentFlowStore;
use Padosoft\LaravelFlow\Persistence\ExecutionScopedPayloadRedactor;
use Padosoft\LaravelFlow\Persistence\KeyBasedPayloadRedactor;
use Throwable;

/**
 * Service provider for padosoft/laravel-flow.
 *
 * Registers {@see FlowEngine} as a container singleton and exposes the
 * opt-in persistence repositories used by v0.2.
 */
final class LaravelFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-flow.php',
            'laravel-flow',
        );

        $this->app->singleton(FlowEngine::class, function (Container $app): FlowEngine {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('laravel-flow', []);
            /** @var Dispatcher $events */
            $events = $app->make(Dispatcher::class);

            return new FlowEngine(
                $app,
                $events,
                $config,
                clock: static fn (): \DateTimeImmutable => Date::now()->toDateTimeImmutable(),
                compensationConcurrencyDriver: $this->compensationConcurrencyDriver($app, $config),
            );
        });

        $this->app->singleton(PayloadRedactor::class, function (Container $app): PayloadRedactor {
            /** @var array<string, mixed> $redaction */
            $redaction = $app['config']->get('laravel-flow.persistence.redaction', []);

            return new KeyBasedPayloadRedactor(
                enabled: (bool) ($redaction['enabled'] ?? true),
                keys: array_values(array_filter((array) ($redaction['keys'] ?? []), 'is_string')),
                replacement: (string) ($redaction['replacement'] ?? '[redacted]'),
            );
        });

        $this->app->singleton(ExecutionScopedPayloadRedactor::class, fn (Container $app): ExecutionScopedPayloadRedactor => new ExecutionScopedPayloadRedactor($app));

        $this->app->singleton(FlowStore::class, function (Container $app): FlowStore {
            /** @var string|null $connection */
            $connection = $app['config']->get('laravel-flow.default_storage');

            return new EloquentFlowStore(
                connection: $connection,
                redactor: $app->make(ExecutionScopedPayloadRedactor::class),
            );
        });

        $this->app->bind(RunRepository::class, fn (Container $app): RunRepository => $app->make(FlowStore::class)->runs());
        $this->app->bind(StepRunRepository::class, fn (Container $app): StepRunRepository => $app->make(FlowStore::class)->steps());
        $this->app->bind(AuditRepository::class, fn (Container $app): AuditRepository => $app->make(FlowStore::class)->audit());
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-flow.php' => $this->configPath('laravel-flow.php'),
        ], 'laravel-flow-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_05_02_000001_create_laravel_flow_tables.php' => $this->app->databasePath('migrations/2026_05_02_000001_create_laravel_flow_tables.php'),
            __DIR__.'/../database/migrations/2026_05_04_000002_add_replay_lineage_to_laravel_flow_runs.php' => $this->app->databasePath('migrations/2026_05_04_000002_add_replay_lineage_to_laravel_flow_runs.php'),
            __DIR__.'/../database/migrations/2026_05_04_000003_create_laravel_flow_approval_and_webhook_tables.php' => $this->app->databasePath('migrations/2026_05_04_000003_create_laravel_flow_approval_and_webhook_tables.php'),
        ], 'laravel-flow-migrations');

        $this->commands([
            PruneFlowRunsCommand::class,
            ReplayFlowRunCommand::class,
        ]);
    }

    private function configPath(string $file): string
    {
        // Avoid hard-binding to the global helper for testability.
        return function_exists('config_path')
            ? config_path($file)
            : $this->app->basePath('config/'.$file);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function compensationConcurrencyDriver(Container $app, array $config): ?ConcurrencyDriver
    {
        if (($config['compensation_strategy'] ?? 'reverse-order') !== 'parallel') {
            return null;
        }

        if (! class_exists(ConcurrencyManager::class)) {
            return null;
        }

        $driverName = (string) ($config['compensation_parallel_driver'] ?? 'process');

        try {
            $driver = $app->make(ConcurrencyManager::class)->driver($driverName);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof ConcurrencyDriver ? $driver : null;
    }
}
