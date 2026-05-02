<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelFlowServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Flow' => Flow::class,
        ];
    }
}
