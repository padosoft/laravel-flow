<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\Exceptions\DuplicateNodeTypeException;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
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

    public function test_config_handlers_win_over_discovery_on_type_collision(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
        $this->app['config']->set('laravel-flow.nodes.discovery', [[
            'path' => __DIR__.'/../../Fixtures/Nodes',
            'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes',
        ]]);
        $this->app->forgetInstance(NodeRegistry::class);

        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertTrue($registry->has('test.upper'));
        $this->assertCount(2, $registry->all());
        $this->assertSame(GreetNode::class, $registry->get('test.greet')->handlerClass);
    }

    public function test_malformed_discovered_node_fails_fast(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', []);
        $this->app['config']->set('laravel-flow.nodes.discovery', [[
            'path' => __DIR__.'/../../Fixtures/InvalidNodes',
            'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\InvalidNodes',
        ]]);
        $this->app->forgetInstance(NodeRegistry::class);

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/duplicate input port/i');
        $this->app->make(NodeRegistry::class);
    }

    public function test_config_wins_even_when_shadowed_discovered_class_is_malformed(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
        $this->app['config']->set('laravel-flow.nodes.discovery', [[
            'path' => __DIR__.'/../../Fixtures/ShadowedNodes',
            'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\ShadowedNodes',
        ]]);
        $this->app->forgetInstance(NodeRegistry::class);

        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertSame(GreetNode::class, $registry->get('test.greet')->handlerClass);
        $this->assertCount(1, $registry->all());
    }

    public function test_discovery_vs_discovery_type_collision_fails_fast(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', []);
        $this->app['config']->set('laravel-flow.nodes.discovery', [[
            'path' => __DIR__.'/../../Fixtures/CollidingNodes',
            'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\CollidingNodes',
        ]]);
        $this->app->forgetInstance(NodeRegistry::class);

        $this->expectException(DuplicateNodeTypeException::class);
        $this->app->make(NodeRegistry::class);
    }

    public function test_malformed_discovery_roots_are_skipped(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', []);
        $this->app['config']->set('laravel-flow.nodes.discovery', [
            'not-an-array',
            ['path' => __DIR__.'/../../Fixtures/Nodes'],
            ['namespace' => 'App\\Nowhere'],
            ['path' => 42, 'namespace' => 'App\\Nowhere'],
            ['path' => __DIR__.'/../../Fixtures/Nodes', 'namespace' => ['nested']],
            ['path' => '', 'namespace' => 'App\\Nowhere'],
            ['path' => '   ', 'namespace' => 'App\\Nowhere'],
            ['path' => __DIR__.'/../../Fixtures/Nodes', 'namespace' => '\\'],
            ['path' => __DIR__.'/../../Fixtures/Nodes', 'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes'],
        ]);
        $this->app->forgetInstance(NodeRegistry::class);

        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertTrue($registry->has('test.upper'));
        $this->assertCount(2, $registry->all());
    }
}
