<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\Exceptions\FlowNotRegisteredException;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\FlowRun;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\DryRunAwareHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThirdHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\ThrowingHandler;

final class FlowEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AlwaysSucceedsHandler::$callCount = 0;
    }

    public function test_execute_happy_path_runs_all_steps_in_order_and_marks_succeeded(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.happy')
            ->withInput(['x'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', SecondHandler::class)
            ->step('three', ThirdHandler::class)
            ->register();

        $run = $engine->execute('flow.happy', ['x' => 1]);

        $this->assertInstanceOf(FlowRun::class, $run);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNull($run->failedStep);
        $this->assertFalse($run->compensated);
        $this->assertFalse($run->dryRun);
        $this->assertSame(['one', 'two', 'three'], array_keys($run->stepResults));
        $this->assertTrue($run->stepResults['one']->success);
        $this->assertTrue($run->stepResults['two']->success);
        $this->assertTrue($run->stepResults['three']->success);
        $this->assertNotNull($run->finishedAt);
        $this->assertSame(1, AlwaysSucceedsHandler::$callCount);
    }

    public function test_execute_throws_when_required_input_key_missing(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.input')
            ->withInput(['a', 'b'])
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $this->expectException(FlowInputException::class);
        $this->expectExceptionMessage('a, b');

        $engine->execute('flow.input', []);
    }

    public function test_execute_throws_when_unknown_definition_requested(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $this->expectException(FlowNotRegisteredException::class);

        $engine->execute('flow.unknown', []);
    }

    public function test_dry_run_flags_run_and_skips_non_supporting_steps(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.dry')
            ->step('plain', AlwaysSucceedsHandler::class)
            ->step('aware', DryRunAwareHandler::class)
            ->withDryRun(true)
            ->register();

        $run = $engine->dryRun('flow.dry', []);

        $this->assertTrue($run->dryRun);
        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertTrue($run->stepResults['plain']->dryRunSkipped);
        $this->assertFalse($run->stepResults['aware']->dryRunSkipped);
        $this->assertSame(['dry_run' => true], $run->stepResults['aware']->output);
        $this->assertSame(['projected_writes' => 0], $run->stepResults['aware']->businessImpact);
        // Non-supporting handler is skipped, so its real callable is NOT invoked.
        $this->assertSame(0, AlwaysSucceedsHandler::$callCount);
    }

    public function test_thrown_exception_in_handler_becomes_failed_step_result(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.boom')
            ->step('crashy', ThrowingHandler::class)
            ->register();

        $run = $engine->execute('flow.boom', []);

        $this->assertSame(FlowRun::STATUS_FAILED, $run->status);
        $this->assertSame('crashy', $run->failedStep);
        $this->assertFalse($run->stepResults['crashy']->success);
        $this->assertNotNull($run->stepResults['crashy']->error);
        $this->assertSame('handler exploded', $run->stepResults['crashy']->error->getMessage());
    }

    public function test_unresolvable_handler_class_results_in_failed_step(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.bad-handler')
            ->step('one', 'NotAClassThatExists')
            ->register();

        $run = $engine->execute('flow.bad-handler', []);

        $this->assertSame(FlowRun::STATUS_FAILED, $run->status);
        $this->assertSame('one', $run->failedStep);
        $this->assertFalse($run->stepResults['one']->success);
    }

    public function test_run_id_is_unique_uuid_v4_shape(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.id')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $run1 = $engine->execute('flow.id', []);
        $run2 = $engine->execute('flow.id', []);

        $this->assertNotSame($run1->id, $run2->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $run1->id,
        );
    }

    public function test_step_outputs_accumulate_into_context_for_downstream_steps(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.chain')
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', SecondHandler::class)
            ->register();

        $run = $engine->execute('flow.chain', []);

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(['phase' => 'second'], $run->stepResults['two']->output);
    }
}
