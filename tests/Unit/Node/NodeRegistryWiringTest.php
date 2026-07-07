<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;

final class NodeRegistryWiringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    public function test_registry_is_singleton_and_loads_configured_handlers(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertSame($registry, $this->app->make(NodeRegistry::class));
    }

    public function test_non_string_config_entries_are_ignored(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class, 42, null, ['nested']]);
        $this->app->forgetInstance(NodeRegistry::class);

        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertCount(1, $registry->all());
    }

    public function test_discovery_roots_are_registered(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', []);
        $this->app['config']->set('laravel-flow.nodes.discovery', [[
            'path' => __DIR__.'/../../Fixtures/Nodes',
            'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes',
        ]]);
        $this->app->forgetInstance(NodeRegistry::class);

        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertTrue($registry->has('test.upper'));
    }
}
