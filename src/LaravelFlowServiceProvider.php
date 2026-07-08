<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlow\Console\ApproveFlowCommand;
use Padosoft\LaravelFlow\Console\DeliverWebhookOutboxCommand;
use Padosoft\LaravelFlow\Console\NodeCatalogCommand;
use Padosoft\LaravelFlow\Console\PruneFlowRunsCommand;
use Padosoft\LaravelFlow\Console\RejectFlowCommand;
use Padosoft\LaravelFlow\Console\ReplayFlowRunCommand;
use Padosoft\LaravelFlow\Contracts\ApprovalRepository;
use Padosoft\LaravelFlow\Contracts\AuditRepository;
use Padosoft\LaravelFlow\Contracts\FlowStore;
use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Contracts\RunRepository;
use Padosoft\LaravelFlow\Contracts\StepRunRepository;
use Padosoft\LaravelFlow\Dashboard\Authorization\DashboardActionAuthorizer;
use Padosoft\LaravelFlow\Dashboard\Authorization\DenyAllAuthorizer;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\NodeCatalog;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeDiscovery;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Persistence\EloquentApprovalRepository;
use Padosoft\LaravelFlow\Persistence\EloquentFlowStore;
use Padosoft\LaravelFlow\Persistence\EloquentWebhookOutboxRepository;
use Padosoft\LaravelFlow\Persistence\ExecutionScopedPayloadRedactor;
use Padosoft\LaravelFlow\Persistence\KeyBasedPayloadRedactor;
use Throwable;

/**
 * Service provider for padosoft/laravel-flow.
 *
 * Registers {@see FlowEngine} as a container singleton and exposes the
 * opt-in persistence repositories used by v0.2.
 *
 * @internal The service provider class is wired by Laravel package auto-
 * discovery and is not part of the v1.0 SemVer-covered surface. Host
 * applications must not extend this class; bind your own contracts on
 * top of {@see Contracts} interfaces instead.
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
        $this->app->singleton(EloquentWebhookOutboxRepository::class, function (Container $app): EloquentWebhookOutboxRepository {
            /** @var string|null $connection */
            $connection = $app['config']->get('laravel-flow.default_storage');

            return new EloquentWebhookOutboxRepository(
                connection: $connection,
                redactor: $app->make(ExecutionScopedPayloadRedactor::class),
            );
        });
        $this->app->singleton(WebhookDeliveryClient::class, function (Container $app): WebhookDeliveryClient {
            /** @var mixed $secret */
            $secret = $app['config']->get('laravel-flow.webhook.secret');
            /** @var mixed $timeoutSeconds */
            $timeoutSeconds = $app['config']->get('laravel-flow.webhook.timeout_seconds', 5);

            return new WebhookDeliveryClient(
                timeoutSeconds: is_numeric($timeoutSeconds) && (int) $timeoutSeconds >= 1 ? (int) $timeoutSeconds : 5,
                secret: is_string($secret) && $secret !== '' ? $secret : null,
            );
        });
        $this->app->bind(ApprovalRepository::class, function (Container $app): ApprovalRepository {
            /** @var string|null $connection */
            $connection = $app['config']->get('laravel-flow.default_storage');

            return new EloquentApprovalRepository(
                connection: $connection,
                redactor: $app->make(ExecutionScopedPayloadRedactor::class),
            );
        });
        $this->app->singleton(ApprovalTokenManager::class, function (Container $app): ApprovalTokenManager {
            /** @var mixed $ttlMinutes */
            $ttlMinutes = $app['config']->get('laravel-flow.approval.token_ttl_minutes', 1440);

            return new ApprovalTokenManager(
                approvals: $app->make(ApprovalRepository::class),
                tokenTtlMinutes: is_numeric($ttlMinutes) && (int) $ttlMinutes >= 1 ? (int) $ttlMinutes : 1440,
                clock: static fn (): \DateTimeImmutable => Date::now()->toDateTimeImmutable(),
            );
        });
        $this->app->singleton(FlowDashboardReadModel::class, function (Container $app): FlowDashboardReadModel {
            /** @var string|null $connection */
            $connection = $app['config']->get('laravel-flow.default_storage');

            return new FlowDashboardReadModel(connection: $connection);
        });
        $this->app->bind(DashboardActionAuthorizer::class, DenyAllAuthorizer::class);

        $this->app->singleton(NodeDefinitionFactory::class);
        $this->app->singleton(NodeRegistry::class, function (Container $app): NodeRegistry {
            $registry = new NodeRegistry($app->make(NodeDefinitionFactory::class));
            /** @var mixed $configured */
            $configured = $app['config']->get('laravel-flow.nodes.handlers', []);
            /** @var list<class-string> $handlers */
            $handlers = array_values(array_filter(
                is_array($configured) ? $configured : [],
                static fn (mixed $handler): bool => is_string($handler),
            ));
            $registry->registerMany($handlers);
            $this->discoverNodes($registry, $app);

            return $registry;
        });
        $this->app->singleton(NodeCatalog::class);
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
            __DIR__.'/../database/migrations/2026_05_04_000004_add_previous_token_hash_to_flow_approvals.php' => $this->app->databasePath('migrations/2026_05_04_000004_add_previous_token_hash_to_flow_approvals.php'),
        ], 'laravel-flow-migrations');

        $this->commands([
            ApproveFlowCommand::class,
            RejectFlowCommand::class,
            DeliverWebhookOutboxCommand::class,
            PruneFlowRunsCommand::class,
            ReplayFlowRunCommand::class,
            NodeCatalogCommand::class,
        ]);
    }

    private function discoverNodes(NodeRegistry $registry, Container $app): void
    {
        /** @var mixed $configured */
        $configured = $app['config']->get('laravel-flow.nodes.discovery', []);
        $discovery = new NodeDiscovery;
        $configRegisteredTypes = array_keys($registry->all());

        foreach ($this->sanitizeDiscoveryRoots($configured) as $root) {
            foreach ($discovery->discover($root['path'], $root['namespace']) as $class) {
                if (in_array($this->declaredNodeType($class), $configRegisteredTypes, true)) {
                    // Config-registered handlers win over discovery: skip the
                    // shadowed class BEFORE validating it, so a malformed
                    // override target cannot fail application boot.
                    continue;
                }

                // Any duplicate surfacing from register() is now necessarily
                // discovery-vs-discovery: a definition error, fail fast.
                $registry->register($class);
            }
        }
    }

    /**
     * @param  class-string  $class
     */
    private function declaredNodeType(string $class): ?string
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(FlowNode::class);

        if ($attributes === []) {
            return null;
        }

        try {
            return $attributes[0]->newInstance()->type;
        } catch (Throwable) {
            // Malformed payload: fall through so register() raises the
            // canonical InvalidNodeDefinitionException.
            return null;
        }
    }

    /**
     * @return list<array{path: string, namespace: string}>
     */
    private function sanitizeDiscoveryRoots(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $roots = [];

        foreach ($configured as $root) {
            if (! is_array($root) || ! isset($root['path'], $root['namespace'])) {
                continue;
            }

            if (! is_string($root['path']) || ! is_string($root['namespace'])) {
                continue;
            }

            // Blank paths would make realpath('') resolve to the CWD and
            // scan the whole application; blank namespaces produce garbage
            // FQCNs. Both are misconfigurations: skip them. Store the
            // trimmed values so padded entries cannot silently no-op later
            // (realpath/class_exists would fail on the padded originals).
            $path = trim($root['path']);
            $namespace = trim($root['namespace']);

            if ($path === '' || trim($namespace, '\\') === '') {
                continue;
            }

            $roots[] = ['path' => $path, 'namespace' => $namespace];
        }

        return $roots;
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
