<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for padosoft/laravel-flow.
 *
 * Registers {@see FlowEngine} as a container singleton + publishes the
 * package config. Migrations are reserved for v0.2 (queued runs +
 * persisted audit trail); v0.1 keeps everything in-memory.
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

            return new FlowEngine($app, $events, $config);
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-flow.php' => $this->configPath('laravel-flow.php'),
        ], 'laravel-flow-config');

        // Migration publishing reserved for v0.2 — flow_runs / flow_steps /
        // flow_audit tables. v0.1 has no DB-backed state.
    }

    private function configPath(string $file): string
    {
        // Avoid hard-binding to the global helper for testability.
        return function_exists('config_path')
            ? config_path($file)
            : $this->app->basePath('config/'.$file);
    }
}
