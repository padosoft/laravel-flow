<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Executor\Nodes;

use Padosoft\LaravelFlow\Executor\Nodes\ApprovalGateNode;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\TestCase;

final class ApprovalGateNodeTest extends TestCase
{
    public function test_execute_pauses(): void
    {
        $context = new NodeContext('run-1', 'flow.demo', 'gate', []);

        $result = (new ApprovalGateNode)->execute($context);

        $this->assertTrue($result->paused);
        $this->assertTrue($result->success, 'a paused result carries success=true per NodeResult::paused() contract');
        $this->assertSame([], $result->outputs, 'outputs are keyed by output-port key; the declared `out` port has nothing to route yet');
    }

    public function test_dry_run_skips_without_pausing(): void
    {
        // Mirrors every other control node: a dry run must have zero side
        // effects (no pause, no token issuance would ever be attempted).
        $context = new NodeContext('run-1', 'flow.demo', 'gate', [], dryRun: true);

        $result = (new ApprovalGateNode)->execute($context);

        $this->assertFalse($result->paused);
        $this->assertTrue($result->dryRunSkipped);
    }

    public function test_registered_as_builtin(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('flow.approval'));

        $definition = $registry->get('flow.approval');
        $this->assertSame(ApprovalGateNode::class, $definition->handlerClass);

        $out = $definition->output('out');
        $this->assertNotNull($out);
    }
}
