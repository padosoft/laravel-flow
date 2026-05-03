<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Database\Migrations\Migration;
use Padosoft\LaravelFlow\Tests\TestCase;

abstract class PersistenceTestCase extends TestCase
{
    private Migration $flowMigration;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'database' => ':memory:',
            'driver' => 'sqlite',
            'foreign_key_constraints' => true,
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->flowMigration = require __DIR__.'/../../../database/migrations/2026_05_02_000001_create_laravel_flow_tables.php';
        $this->flowMigration->down();
    }

    protected function tearDown(): void
    {
        $this->flowMigration->down();

        parent::tearDown();
    }

    protected function migrateFlowTables(): void
    {
        $this->flowMigration->up();
    }

    protected function dropFlowTables(): void
    {
        $this->flowMigration->down();
    }
}
