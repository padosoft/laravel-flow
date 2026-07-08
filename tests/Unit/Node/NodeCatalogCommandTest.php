<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;

final class NodeCatalogCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    public function test_json_output_is_valid_catalog(): void
    {
        $this->artisan('flow:nodes', ['--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('"test.greet"');
    }

    public function test_table_output_lists_types(): void
    {
        $this->artisan('flow:nodes')
            ->assertExitCode(0)
            ->expectsOutputToContain('test.greet');
    }
}
