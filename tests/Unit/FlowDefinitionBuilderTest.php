<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Exceptions\FlowExecutionException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;

final class FlowDefinitionBuilderTest extends TestCase
{
    public function test_register_persists_a_definition_retrievable_via_definitions_map(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.alpha')
            ->withInput(['a', 'b'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $defs = $engine->definitions();
        $this->assertArrayHasKey('flow.alpha', $defs);
        $this->assertSame('flow.alpha', $defs['flow.alpha']->name);
        $this->assertSame(['a', 'b'], $defs['flow.alpha']->requiredInputs);
        $this->assertCount(1, $defs['flow.alpha']->steps);
        $this->assertSame('one', $defs['flow.alpha']->steps[0]->name);
        $this->assertSame(AlwaysSucceedsHandler::class, $defs['flow.alpha']->steps[0]->handlerFqcn);
    }

    public function test_with_input_deduplicates_required_keys(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.dedupe')
            ->withInput(['a', 'b', 'a', 'c', 'b'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->assertSame(['a', 'b', 'c'], $engine->definition('flow.dedupe')->requiredInputs);
    }

    public function test_with_dry_run_targets_the_last_step(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.dr')
            ->step('first', AlwaysSucceedsHandler::class)
            ->step('second', SecondHandler::class)
            ->withDryRun(true)
            ->register();

        $steps = $engine->definition('flow.dr')->steps;
        $this->assertFalse($steps[0]->supportsDryRun);
        $this->assertTrue($steps[1]->supportsDryRun);
    }

    public function test_compensate_with_targets_the_last_step(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.cw')
            ->step('first', AlwaysSucceedsHandler::class)
            ->step('second', SecondHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->register();

        $steps = $engine->definition('flow.cw')->steps;
        $this->assertNull($steps[0]->compensatorFqcn);
        $this->assertSame(FirstStepCompensator::class, $steps[1]->compensatorFqcn);
    }

    public function test_register_without_steps_throws(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('zero steps');

        $engine->define('flow.empty')->withInput(['x'])->register();
    }

    public function test_with_dry_run_before_first_step_throws(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);
        $this->expectExceptionMessage('no step has been added yet');

        $engine->define('flow.bad')->withInput(['x'])->withDryRun(true);
    }

    public function test_compensate_with_before_first_step_throws(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowExecutionException::class);

        $engine->define('flow.bad2')->compensateWith(FirstStepCompensator::class);
    }

    public function test_aggregate_compensator_is_stored_on_definition(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.agg')
            ->step('first', AlwaysSucceedsHandler::class)
            ->withAggregateCompensator(FirstStepCompensator::class)
            ->register();

        $this->assertSame(
            FirstStepCompensator::class,
            $engine->definition('flow.agg')->aggregateCompensatorFqcn,
        );
    }
}
