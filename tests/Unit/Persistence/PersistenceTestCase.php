<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Illuminate\Database\Migrations\Migration;
use Padosoft\LaravelFlow\Tests\TestCase;

abstract class PersistenceTestCase extends TestCase
{
    /**
     * @var list<Migration>
     */
    private array $flowMigrations = [];

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

        $this->flowMigrations = [
            require __DIR__.'/../../../database/migrations/2026_05_02_000001_create_laravel_flow_tables.php',
            require __DIR__.'/../../../database/migrations/2026_05_04_000002_add_replay_lineage_to_laravel_flow_runs.php',
            require __DIR__.'/../../../database/migrations/2026_05_04_000003_create_laravel_flow_approval_and_webhook_tables.php',
            require __DIR__.'/../../../database/migrations/2026_05_04_000004_add_previous_token_hash_to_flow_approvals.php',
        ];
        $this->dropFlowTables();
    }

    protected function tearDown(): void
    {
        $this->dropFlowTables();

        parent::tearDown();
    }

    protected function migrateFlowTables(): void
    {
        foreach ($this->flowMigrations as $migration) {
            $migration->up();
        }
    }

    protected function dropFlowTables(): void
    {
        foreach (array_reverse($this->flowMigrations) as $migration) {
            $migration->down();
        }
    }
}
