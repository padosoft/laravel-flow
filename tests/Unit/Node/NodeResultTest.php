<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
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
