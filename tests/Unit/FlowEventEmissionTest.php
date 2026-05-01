<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Padosoft\LaravelFlow\Events\FlowCompensated;
use Padosoft\LaravelFlow\Events\FlowStepCompleted;
use Padosoft\LaravelFlow\Events\FlowStepFailed;
use Padosoft\LaravelFlow\Events\FlowStepStarted;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Tests\TestCase;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysFailsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\AlwaysSucceedsHandler;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\FirstStepCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\RecordingCompensator;
use Padosoft\LaravelFlow\Tests\Unit\Stubs\SecondHandler;

final class FlowEventEmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingCompensator::reset();
    }

    public function test_happy_path_emits_started_and_completed_events_per_step(): void
    {
        Event::fake([FlowStepStarted::class, FlowStepCompleted::class, FlowStepFailed::class, FlowCompensated::class]);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.events.happy')
            ->step('one', AlwaysSucceedsHandler::class)
            ->step('two', SecondHandler::class)
            ->register();

        $run = $engine->execute('flow.events.happy', []);

        Event::assertDispatched(FlowStepStarted::class, fn (FlowStepStarted $e): bool => $e->stepName === 'one' && $e->flowRunId === $run->id);
        Event::assertDispatched(FlowStepStarted::class, fn (FlowStepStarted $e): bool => $e->stepName === 'two');
        Event::assertDispatched(FlowStepCompleted::class, fn (FlowStepCompleted $e): bool => $e->stepName === 'one');
        Event::assertDispatched(FlowStepCompleted::class, fn (FlowStepCompleted $e): bool => $e->stepName === 'two');
        Event::assertNotDispatched(FlowStepFailed::class);
        Event::assertNotDispatched(FlowCompensated::class);
    }

    public function test_failure_path_emits_failed_event_for_failing_step_and_compensated_event_for_each_unwound_step(): void
    {
        Event::fake([FlowStepStarted::class, FlowStepCompleted::class, FlowStepFailed::class, FlowCompensated::class]);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.events.fail')
            ->step('one', AlwaysSucceedsHandler::class)
            ->compensateWith(FirstStepCompensator::class)
            ->step('two', AlwaysFailsHandler::class)
            ->register();

        $engine->execute('flow.events.fail', []);

        Event::assertDispatched(FlowStepCompleted::class, fn (FlowStepCompleted $e): bool => $e->stepName === 'one');
        Event::assertDispatched(FlowStepFailed::class, fn (FlowStepFailed $e): bool => $e->stepName === 'two');
        Event::assertDispatched(FlowCompensated::class, fn (FlowCompensated $e): bool => $e->stepName === 'one');
        Event::assertNotDispatched(FlowStepCompleted::class, fn (FlowStepCompleted $e): bool => $e->stepName === 'two');
    }

    public function test_audit_disabled_suppresses_all_event_emission(): void
    {
        // Override config to disable the audit trail and rebuild the singleton.
        $this->app['config']->set('laravel-flow.audit_trail_enabled', false);
        $this->app->forgetInstance(FlowEngine::class);

        Event::fake([FlowStepStarted::class, FlowStepCompleted::class, FlowStepFailed::class, FlowCompensated::class]);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.silent')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->execute('flow.silent', []);

        Event::assertNotDispatched(FlowStepStarted::class);
        Event::assertNotDispatched(FlowStepCompleted::class);
    }

    public function test_dry_run_flag_propagates_into_emitted_events(): void
    {
        Event::fake([FlowStepStarted::class, FlowStepCompleted::class]);

        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        $engine->define('flow.events.dry')
            ->step('one', AlwaysSucceedsHandler::class)
            ->register();

        $engine->dryRun('flow.events.dry', []);

        Event::assertDispatched(FlowStepStarted::class, fn (FlowStepStarted $e): bool => $e->dryRun === true);
        Event::assertDispatched(FlowStepCompleted::class, fn (FlowStepCompleted $e): bool => $e->dryRun === true);
    }
}
