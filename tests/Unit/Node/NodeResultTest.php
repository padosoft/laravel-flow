<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NodeResultTest extends TestCase
{
    public function test_factories_mirror_flow_step_result_semantics(): void
    {
        $ok = NodeResult::success(['greeting' => 'ciao'], ['emails_sent' => 1]);
        $this->assertTrue($ok->success);
        $this->assertSame(['greeting' => 'ciao'], $ok->outputs);
        $this->assertSame(['emails_sent' => 1], $ok->businessImpact);
        $this->assertFalse($ok->dryRunSkipped);
        $this->assertFalse($ok->paused);

        $error = new RuntimeException('boom');
        $failed = NodeResult::failed($error);
        $this->assertFalse($failed->success);
        $this->assertSame($error, $failed->error);
        $this->assertSame([], $failed->outputs);

        $skipped = NodeResult::dryRunSkipped();
        $this->assertTrue($skipped->success);
        $this->assertTrue($skipped->dryRunSkipped);

        $paused = NodeResult::paused(['token' => 'x']);
        $this->assertTrue($paused->paused);
        $this->assertSame(['token' => 'x'], $paused->outputs);
    }

    public function test_branch_activates_only_the_listed_ports(): void
    {
        $result = NodeResult::branch(['yes' => 1, 'no' => 2], ['yes'], ['n' => 1]);

        $this->assertTrue($result->success);
        $this->assertFalse($result->paused);
        $this->assertFalse($result->dryRunSkipped);
        $this->assertSame(['yes'], $result->activePorts);
        $this->assertSame(['yes' => 1, 'no' => 2], $result->outputs);
        $this->assertSame(['n' => 1], $result->businessImpact);

        $this->assertNull(NodeResult::success()->activePorts);
        $this->assertNull(NodeResult::paused()->activePorts);
        $this->assertNull(NodeResult::dryRunSkipped()->activePorts);
        $this->assertNull(NodeResult::failed(new RuntimeException('x'))->activePorts);
    }

    /**
     * @return array<string, array{0: list<mixed>}>
     */
    public static function invalidActivePorts(): array
    {
        return [
            'empty' => [[]],
            'blank' => [['  ']],
            'reserved prefix' => [['_meta']],
            'not a string' => [[1]],
            'duplicate' => [['yes', 'yes']],
        ];
    }

    /**
     * @param  list<mixed>  $ports
     */
    #[DataProvider('invalidActivePorts')]
    public function test_branch_rejects_an_invalid_active_port_list(array $ports): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line argument.type */
        NodeResult::branch([], $ports);
    }

    public function test_handler_executes_against_context(): void
    {
        $context = new NodeContext(
            flowRunId: 'run-1',
            definitionName: 'demo',
            nodeId: 'node-1',
            inputs: ['name' => 'Ada'],
        );

        $result = (new GreetNode)->execute($context);

        $this->assertTrue($result->success);
        $this->assertSame(['greeting' => 'Hello Ada'], $result->outputs);
        $this->assertFalse($context->dryRun);
    }
}
