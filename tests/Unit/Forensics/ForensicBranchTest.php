<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Forensics;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Executor\GraphRunner;
use Padosoft\LaravelFlow\Forensics\ForensicBundle;
use Padosoft\LaravelFlow\Forensics\ForensicExporter;
use Padosoft\LaravelFlow\Forensics\ForensicFinding;
use Padosoft\LaravelFlow\Forensics\ForensicVerifier;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\BranchingNode;
use Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes\QueueProbeNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * A branch decision is execution state: a bundle that dropped it could not say
 * why a descendant was skipped, and could not tell "the node omitted an output"
 * from "the node deactivated that port".
 */
final class ForensicBranchTest extends PersistenceTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        $app['config']->set('laravel-flow.nodes.handlers', [BranchingNode::class, QueueProbeNode::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFlowTables();
        QueueProbeNode::reset();
    }

    private function graph(): GraphDefinition
    {
        return new GraphDefinition(
            [
                new GraphNode('c', 'test.branch', ['take' => 'yes']),
                new GraphNode('a', 'test.probe'),
                new GraphNode('b', 'test.probe'),
                new GraphNode('b2', 'test.probe'),
            ],
            [
                new Connection('c', 'yes', 'a', 'in'),
                new Connection('c', 'no', 'b', 'in'),
                new Connection('b', 'out', 'b2', 'in'),
            ],
        );
    }

    private function exportedBundle(): ForensicBundle
    {
        $result = $this->app->make(GraphRunner::class)->run($this->graph(), []);

        return (new ForensicExporter($this->app->make(PayloadRedactor::class)))->export($result->runId, redact: false);
    }

    private function verifier(): ForensicVerifier
    {
        return new ForensicVerifier($this->app->make(NodeRegistry::class));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function nodesById(ForensicBundle $bundle): array
    {
        $byId = [];

        foreach ($bundle->nodes as $row) {
            $byId[(string) $row['node_id']] = $row;
        }

        return $byId;
    }

    public function test_the_bundle_records_the_branch_decision_only_where_one_was_made(): void
    {
        $nodes = $this->nodesById($this->exportedBundle());

        $this->assertSame(['yes'], $nodes['c']['active_ports']);
        $this->assertSame([], $nodes['b']['active_ports']);
        $this->assertSame([], $nodes['b2']['active_ports']);
        // An ordinary node adds no key, so non-branching bundles keep their digest.
        $this->assertArrayNotHasKey('active_ports', $nodes['a']);
    }

    public function test_a_faithful_branching_run_verifies_and_reports_the_confirmed_skips(): void
    {
        $bundle = $this->exportedBundle();
        $report = $this->verifier()->verify($bundle);

        $failed = array_map(static fn (ForensicFinding $f): string => $f->detail, $report->ofStatus(ForensicFinding::FAILED));
        $this->assertSame([], array_filter($failed, static fn (string $m): bool => str_contains($m, 'skipped') || str_contains($m, 'dead')));

        $ok = array_map(static fn (ForensicFinding $f): string => $f->detail, $report->ofStatus(ForensicFinding::OK));
        $this->assertContains('2 branch skip(s) confirmed against the recorded branch decisions.', $ok);
    }

    public function test_a_node_that_ran_although_its_wires_were_dead_is_caught(): void
    {
        $document = $this->exportedBundle()->toArray();

        foreach ($document['nodes'] as &$row) {
            if ($row['node_id'] === 'b') {
                // Pretend the skipped node actually ran.
                $row['status'] = 'succeeded';
                unset($row['active_ports']);
            }
        }
        unset($row);

        $report = $this->verifier()->verify(ForensicBundle::fromArray($document));
        $failed = array_map(static fn (ForensicFinding $f): string => $f->detail, $report->ofStatus(ForensicFinding::FAILED));

        $this->assertNotEmpty(array_filter($failed, static fn (string $m): bool => str_contains($m, 'every incoming wire was dead')));
    }

    public function test_a_skip_that_the_branch_decisions_do_not_justify_is_caught(): void
    {
        $document = $this->exportedBundle()->toArray();

        foreach ($document['nodes'] as &$row) {
            if ($row['node_id'] === 'c') {
                // Rewrite the decision: c activated BOTH ports, so b should have run.
                $row['active_ports'] = ['yes', 'no'];
            }
        }
        unset($row);

        $report = $this->verifier()->verify(ForensicBundle::fromArray($document));
        $failed = array_map(static fn (ForensicFinding $f): string => $f->detail, $report->ofStatus(ForensicFinding::FAILED));

        $this->assertNotEmpty(array_filter($failed, static fn (string $m): bool => str_contains($m, 'not every incoming wire was dead')));
    }
}
