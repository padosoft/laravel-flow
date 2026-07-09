<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\State;

use Padosoft\LaravelFlow\Executor\State\IllegalStateTransitionException;
use Padosoft\LaravelFlow\Executor\State\RunState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    #[DataProvider('terminalCases')]
    public function test_terminal_states_report_terminal(RunState $state, bool $expected): void
    {
        $this->assertSame($expected, $state->isTerminal());
    }

    /**
     * @return iterable<string, array{RunState, bool}>
     */
    public static function terminalCases(): iterable
    {
        yield 'pending' => [RunState::Pending, false];
        yield 'running' => [RunState::Running, false];
        yield 'paused' => [RunState::Paused, false];
        yield 'failed' => [RunState::Failed, true];
        yield 'partially_succeeded' => [RunState::PartiallySucceeded, true];
        yield 'succeeded' => [RunState::Succeeded, true];
        yield 'compensated' => [RunState::Compensated, true];
        yield 'aborted' => [RunState::Aborted, true];
        yield 'dead_letter' => [RunState::DeadLetter, true];
    }

    #[DataProvider('legalTransitions')]
    public function test_legal_transitions_are_allowed(RunState $from, RunState $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
        $this->assertSame($to, $from->transitionTo($to));
    }

    /**
     * @return iterable<string, array{RunState, RunState}>
     */
    public static function legalTransitions(): iterable
    {
        yield 'pending->running' => [RunState::Pending, RunState::Running];
        yield 'running->paused' => [RunState::Running, RunState::Paused];
        yield 'running->succeeded' => [RunState::Running, RunState::Succeeded];
        yield 'running->partially_succeeded' => [RunState::Running, RunState::PartiallySucceeded];
        yield 'running->failed' => [RunState::Running, RunState::Failed];
        yield 'running->aborted' => [RunState::Running, RunState::Aborted];
        yield 'running->dead_letter' => [RunState::Running, RunState::DeadLetter];
        yield 'paused->running' => [RunState::Paused, RunState::Running];
        yield 'paused->failed' => [RunState::Paused, RunState::Failed];
        yield 'paused->aborted' => [RunState::Paused, RunState::Aborted];
        yield 'failed->compensated' => [RunState::Failed, RunState::Compensated];
        yield 'partially_succeeded->compensated' => [RunState::PartiallySucceeded, RunState::Compensated];
    }

    #[DataProvider('illegalTransitions')]
    public function test_illegal_transitions_throw(RunState $from, RunState $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));

        $this->expectException(IllegalStateTransitionException::class);
        $this->expectExceptionMessage($from->value);
        $this->expectExceptionMessage($to->value);

        $from->transitionTo($to);
    }

    /**
     * @return iterable<string, array{RunState, RunState}>
     */
    public static function illegalTransitions(): iterable
    {
        yield 'succeeded->running' => [RunState::Succeeded, RunState::Running];
        yield 'pending->succeeded' => [RunState::Pending, RunState::Succeeded];
        yield 'compensated->running' => [RunState::Compensated, RunState::Running];
        yield 'aborted->running' => [RunState::Aborted, RunState::Running];
        yield 'dead_letter->compensated' => [RunState::DeadLetter, RunState::Compensated];
        yield 'running->compensated' => [RunState::Running, RunState::Compensated];
    }
}
