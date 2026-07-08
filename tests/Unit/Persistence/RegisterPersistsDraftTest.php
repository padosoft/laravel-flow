<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Persistence;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Models\FlowDefinitionRecord;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;

final class RegisterPersistsDraftTest extends PersistenceTestCase
{
    private function repository(): DefinitionRepository
    {
        return $this->app->make(DefinitionRepository::class);
    }

    private function enablePersistRegistered(): void
    {
        $this->app['config']->set('laravel-flow.definitions.persist_registered', true);
    }

    public function test_register_with_flag_enabled_creates_a_draft(): void
    {
        $this->migrateFlowTables();
        $this->enablePersistRegistered();

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persisted')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $latest = $this->repository()->latest('flow.persisted');

        $this->assertNotNull($latest);
        $this->assertSame(1, $latest->version);
        $this->assertSame('draft', $latest->status);
    }

    public function test_reregistering_an_identical_definition_does_not_create_a_second_version(): void
    {
        $this->migrateFlowTables();
        $this->enablePersistRegistered();

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persisted')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->define('flow.persisted')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->assertCount(1, $this->repository()->versions('flow.persisted'));
    }

    public function test_reregistering_a_changed_definition_creates_a_new_version(): void
    {
        $this->migrateFlowTables();
        $this->enablePersistRegistered();

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.persisted')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->define('flow.persisted')
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', AlwaysSucceedsHandler::class)
            ->register();

        $this->assertCount(2, $this->repository()->versions('flow.persisted'));
    }

    public function test_flag_default_off_writes_no_rows(): void
    {
        $this->migrateFlowTables();

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.not-persisted')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->assertSame(0, FlowDefinitionRecord::query()->count());
    }

    public function test_register_with_flag_enabled_reports_missing_definitions_table_with_package_message(): void
    {
        $this->enablePersistRegistered();

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        try {
            $engine->define('flow.persisted-without-migrations')
                ->step('one', AlwaysSucceedsHandler::class)
                ->register();
            $this->fail('Missing flow_definitions table should be reported as a package-level configuration failure.');
        } catch (FlowExecutionException $exception) {
            $this->assertMatchesRegularExpression('/flow_definitions|migrations/i', $exception->getMessage());
        }
    }
}
