<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowDefinitionBuilder;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;

final class FlowFacadeTest extends TestCase
{
    public function test_define_returns_a_builder(): void
    {
        $this->assertInstanceOf(FlowDefinitionBuilder::class, Flow::define('flow.facade.def'));
    }

    public function test_full_round_trip_define_execute_via_facade(): void
    {
        Flow::define('flow.facade.full')
            ->withInput(['name'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run = Flow::execute('flow.facade.full', ['name' => 'lorenzo']);

        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
    }

    public function test_dry_run_via_facade_marks_run_as_dry(): void
    {
        Flow::define('flow.facade.dry')
            ->step('aware', DryRunAwareHandler::class)
            ->withDryRun(true)
            ->register();

        $run = Flow::dryRun('flow.facade.dry', []);

        $this->assertTrue($run->dryRun);
        $this->assertSame(['projected_writes' => 0], $run->stepResults['aware']->businessImpact);
    }

    public function test_definitions_returned_via_facade_include_registered_flow(): void
    {
        Flow::define('flow.facade.list')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $defs = Flow::definitions();

        $this->assertArrayHasKey('flow.facade.list', $defs);
    }
}
