<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\State;

use Padosoft\LaravelFlow\Executor\State\IllegalStateTransitionException;
use Padosoft\LaravelFlow\Executor\State\NodeState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NodeStateTest extends TestCase
{
    #[DataProvider('terminalCases')]
    public function test_terminal_states_report_terminal(NodeState $state, bool $expected): void
    {
        $this->assertSame($expected, $state->isTerminal());
    }

    /**
     * @return iterable<string, array{NodeState, bool}>
     */
    public static function terminalCases(): iterable
    {
        yield 'pending' => [NodeState::Pending, false];
        yield 'running' => [NodeState::Running, false];
        yield 'paused' => [NodeState::Paused, false];
        yield 'failed' => [NodeState::Failed, false];
        yield 'succeeded' => [NodeState::Succeeded, true];
        yield 'skipped' => [NodeState::Skipped, true];
        yield 'blocked' => [NodeState::Blocked, true];
        yield 'invalid_input' => [NodeState::InvalidInput, true];
        yield 'dead_letter' => [NodeState::DeadLetter, true];
    }

    #[DataProvider('legalTransitions')]
    public function test_legal_transitions_are_allowed(NodeState $from, NodeState $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
        $this->assertSame($to, $from->transitionTo($to));
    }

    /**
     * @return iterable<string, array{NodeState, NodeState}>
     */
    public static function legalTransitions(): iterable
    {
        yield 'pending->running' => [NodeState::Pending, NodeState::Running];
        yield 'pending->skipped' => [NodeState::Pending, NodeState::Skipped];
        yield 'pending->blocked' => [NodeState::Pending, NodeState::Blocked];
        yield 'pending->invalid_input' => [NodeState::Pending, NodeState::InvalidInput];
        yield 'running->paused' => [NodeState::Running, NodeState::Paused];
        yield 'running->succeeded' => [NodeState::Running, NodeState::Succeeded];
        yield 'running->failed' => [NodeState::Running, NodeState::Failed];
        yield 'running->dead_letter' => [NodeState::Running, NodeState::DeadLetter];
        yield 'paused->running' => [NodeState::Paused, NodeState::Running];
        yield 'paused->failed' => [NodeState::Paused, NodeState::Failed];
        yield 'failed->running' => [NodeState::Failed, NodeState::Running];
        yield 'failed->dead_letter' => [NodeState::Failed, NodeState::DeadLetter];
    }

    #[DataProvider('illegalTransitions')]
    public function test_illegal_transitions_throw(NodeState $from, NodeState $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));

        $this->expectException(IllegalStateTransitionException::class);
        $this->expectExceptionMessage($from->value);
        $this->expectExceptionMessage($to->value);

        $from->transitionTo($to);
    }

    /**
     * @return iterable<string, array{NodeState, NodeState}>
     */
    public static function illegalTransitions(): iterable
    {
        yield 'succeeded->running' => [NodeState::Succeeded, NodeState::Running];
        yield 'pending->succeeded' => [NodeState::Pending, NodeState::Succeeded];
        yield 'blocked->running' => [NodeState::Blocked, NodeState::Running];
        yield 'dead_letter->running' => [NodeState::DeadLetter, NodeState::Running];
        yield 'paused->succeeded' => [NodeState::Paused, NodeState::Succeeded];
        yield 'invalid_input->running' => [NodeState::InvalidInput, NodeState::Running];
        yield 'skipped->running' => [NodeState::Skipped, NodeState::Running];
    }
}
