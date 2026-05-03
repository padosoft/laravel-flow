<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Exceptions\FlowCompensationException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThirdHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThrowingCompensator;
use RuntimeException;

final class FlowEngineCompensationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingCompensator::reset();
    }

    public function test_failure_on_third_step_walks_compensators_for_step_one_and_step_two_in_reverse_order(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.compensate')
            ->step('first', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('second', SecondHandler::class)
            ->compensateWith(SecondStepCompensator::class)
            ->step('third', AlwaysFailsHandler::class)
            ->step('fourth', ThirdHandler::class)
            ->register();

        $run = $engine->execute('flow.compensate', []);

        $this->assertSame(FlowRun::STATUS_COMPENSATED, $run->status);
        $this->assertSame('third', $run->failedStep);
        $this->assertTrue($run->compensated);
        $this->assertCount(2, RecordingCompensator::$invocations);
        // Reverse order: 'second' fires first, then 'first'.
        $this->assertSame('second', RecordingCompensator::$invocations[0]['originalOutput']['compensator']);
        $this->assertSame('first', RecordingCompensator::$invocations[1]['originalOutput']['compensator']);
        // Fourth step never ran (failure short-circuits forward iteration).
        $this->assertArrayNotHasKey('fourth', $run->stepResults);
    }

    public function test_failure_with_no_compensators_marks_failed_but_not_compensated(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.no-comp')
            ->step('first', AlwaysSucceedsHandler::class)
            ->step('second', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.no-comp', []);

        $this->assertSame(FlowRun::STATUS_FAILED, $run->status);
        $this->assertSame('second', $run->failedStep);
        $this->assertFalse($run->compensated);
    }

    public function test_failure_on_first_step_does_not_invoke_any_compensator(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.first-fails')
            ->step('first', AlwaysFailsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('second', AlwaysSucceedsHandler::class)
            ->compensateWith(SecondStepCompensator::class)
            ->register();

        $run = $engine->execute('flow.first-fails', []);

        $this->assertSame(FlowRun::STATUS_FAILED, $run->status);
        $this->assertFalse($run->compensated);
        $this->assertSame([], RecordingCompensator::$invocations);
    }

    public function test_compensator_receives_original_step_output(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.payload')
            ->step('first', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('second', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.payload', []);

        $this->assertSame(FlowRun::STATUS_COMPENSATED, $run->status);
        $this->assertCount(1, RecordingCompensator::$invocations);
        $this->assertArrayHasKey('handler', RecordingCompensator::$invocations[0]['originalOutput']);
        $this->assertArrayHasKey('flow_run_id', RecordingCompensator::$invocations[0]['originalOutput']);
        $this->assertSame($run->id, RecordingCompensator::$invocations[0]['originalOutput']['flow_run_id']);
    }

    public function test_compensation_continues_on_compensator_failure_and_aggregates_errors(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        // Three steps with compensators; the SECOND compensator throws
        // mid-rollback. The engine MUST still call the FIRST compensator
        // (the one we care about most — it ran longest ago) and surface
        // an aggregated FlowCompensationException at the end.
        $engine->define('flow.partial-rollback')
            ->step('first', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('second', SecondHandler::class)
            ->compensateWith(ThrowingCompensator::class)
            ->step('third', AlwaysFailsHandler::class)
            ->register();

        $caught = null;
        try {
            $engine->execute('flow.partial-rollback', []);
        } catch (FlowCompensationException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            FlowCompensationException::class,
            $caught,
            'Engine should aggregate compensator failures into a FlowCompensationException.'
        );
        $this->assertStringContainsString('1 failed compensator', $caught->getMessage());
        $this->assertStringContainsString('[second]', $caught->getMessage());

        // The first-step compensator MUST have fired despite the second-step
        // compensator having thrown earlier in the reverse-order walk.
        $this->assertCount(1, RecordingCompensator::$invocations);
        $this->assertSame('first', RecordingCompensator::$invocations[0]['originalOutput']['compensator']);
    }

    public function test_compensated_event_listener_failure_does_not_abort_in_memory_rollback(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->app['events']->listen(
            FlowCompensated::class,
            static fn (): never => throw new RuntimeException('compensated listener down'),
        );

        $engine->define('flow.compensated-listener-down')
            ->step('first', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('second', SecondHandler::class)
            ->compensateWith(SecondStepCompensator::class)
            ->step('third', AlwaysFailsHandler::class)
            ->register();

        $run = $engine->execute('flow.compensated-listener-down', []);

        $this->assertSame(FlowRun::STATUS_COMPENSATED, $run->status);
        $this->assertCount(2, RecordingCompensator::$invocations);
        $this->assertSame('second', RecordingCompensator::$invocations[0]['originalOutput']['compensator']);
        $this->assertSame('first', RecordingCompensator::$invocations[1]['originalOutput']['compensator']);
    }
}
