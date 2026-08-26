<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Forensics;

use Padosoft\LaravelFlow\Contracts\PayloadRedactor;
use Padosoft\LaravelFlow\Forensics\ForensicBundle;
use Padosoft\LaravelFlow\Forensics\ForensicExporter;
use Padosoft\LaravelFlow\Forensics\ForensicFinding;
use Padosoft\LaravelFlow\Forensics\ForensicVerifier;
use Padosoft\LaravelFlow\Forensics\RunNotExportableException;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Models\FlowRunNodeRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use Padosoft\LaravelFlow\Tests\Unit\Persistence\PersistenceTestCase;

/**
 * A forensic bundle answers a question asked after an incident: what did this
 * run see, and can I show it to someone who does not trust our database?
 *
 * These tests pin the three ways a record can lie — the file was edited, the
 * graph in it is not the graph that ran, the node rows do not follow from each
 * other — plus the one thing a forensic tool must never do: report "fine" when
 * it means "I could not check".
 */
final class ForensicBundleTest extends PersistenceTestCase
{
    private const RUN_ID = '00000000-0000-4000-8000-0000000f0001';

    public function test_export_carries_the_graph_the_run_itself_snapshotted(): void
    {
        $this->migrateFlowTables();
        $this->seedRun();

        $bundle = $this->exporter()->export(self::RUN_ID, redact: false);

        $this->assertSame(ForensicBundle::FORMAT, $bundle->body()['format']);
        $this->assertSame(self::RUN_ID, $bundle->run['id']);
        $this->assertSame('echo-flow', $bundle->definition['name']);
        $this->assertCount(2, $bundle->nodes);
        $this->assertSame('a', $bundle->nodes[0]['node_id']);
        $this->assertSame('b', $bundle->nodes[1]['node_id']);
    }

    public function test_a_run_with_no_record_refuses_instead_of_producing_an_empty_bundle(): void
    {
        // An empty forensic document is the most dangerous possible output: it
        // reads as "nothing happened" and means "we did not keep the record".
        $this->migrateFlowTables();

        $this->expectException(RunNotExportableException::class);

        $this->exporter()->export('00000000-0000-4000-8000-00000000dead');
    }

    public function test_the_digest_ignores_the_export_timestamp(): void
    {
        // Two exports of the same finished run must compare equal, or the digest
        // is useless as an identity.
        $this->migrateFlowTables();
        $this->seedRun();

        $first = $this->exporter()->export(self::RUN_ID, redact: false, exportedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $second = $this->exporter()->export(self::RUN_ID, redact: false, exportedAt: new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        $this->assertSame($first->digest(), $second->digest());
        $this->assertNotSame($first->toArray()['exportedAt'], $second->toArray()['exportedAt']);
    }

    public function test_an_edited_bundle_stops_matching_its_own_digest(): void
    {
        $this->migrateFlowTables();
        $this->seedRun();

        $bundle = $this->exporter()->export(self::RUN_ID, redact: false);
        $document = $bundle->toArray();
        $claimed = $document['contentDigest'];

        // Someone rewrites an output in the file, keeping the digest line.
        $document['nodes'][1]['outputs'] = ['upper' => 'SOMETHING ELSE ENTIRELY'];

        $report = $this->verifier()->verify(ForensicBundle::fromArray($document), $claimed);

        $this->assertFalse($report->intact());
        $this->assertSame(
            [ForensicVerifier::CHECK_DIGEST],
            array_values(array_unique(array_map(
                static fn (ForensicFinding $f): string => $f->check,
                array_filter($report->ofStatus(ForensicFinding::FAILED), static fn (ForensicFinding $f): bool => $f->check === ForensicVerifier::CHECK_DIGEST),
            ))),
        );
    }

    public function test_a_swapped_graph_is_caught_by_the_recorded_checksum(): void
    {
        // The bundle's graph is checked against the checksum the RUN recorded,
        // not against whatever is registered under that name today — which
        // anyone with database access could have changed since.
        $this->migrateFlowTables();
        $this->seedRun();

        $document = $this->exporter()->export(self::RUN_ID, redact: false)->toArray();
        $document['definition']['graph']['nodes'][0]['config'] = ['text' => 'tampered'];

        $report = $this->verifier()->verify(ForensicBundle::fromArray($document));

        $failed = array_map(static fn (ForensicFinding $f): string => $f->check, $report->ofStatus(ForensicFinding::FAILED));

        $this->assertFalse($report->intact());
        $this->assertContains(ForensicVerifier::CHECK_DEFINITION, $failed);
    }

    public function test_an_untampered_bundle_verifies_including_the_routing_replay(): void
    {
        $this->migrateFlowTables();
        $this->seedRun();

        $bundle = $this->exporter()->export(self::RUN_ID, redact: false);
        $report = $this->verifier()->verify($bundle, $bundle->digest());

        $this->assertTrue($report->intact(), print_r($report->toArray(), true));
        $checks = array_map(static fn (ForensicFinding $f): string => $f->check, $report->ofStatus(ForensicFinding::OK));
        $this->assertContains(ForensicVerifier::CHECK_DIGEST, $checks);
        $this->assertContains(ForensicVerifier::CHECK_DEFINITION, $checks);
        $this->assertContains(ForensicVerifier::CHECK_ROUTING, $checks);
    }

    public function test_edited_node_inputs_are_caught_by_the_routing_replay(): void
    {
        // The deterministic half of a flow is the routing between nodes. Re-derive
        // it from the recorded outputs and it must produce the recorded inputs —
        // when it does not, the finding names the node where the two stories
        // first stop agreeing.
        $this->migrateFlowTables();
        $this->seedRun();

        $document = $this->exporter()->export(self::RUN_ID, redact: false)->toArray();
        $document['nodes'][1]['inputs'] = ['text' => 'not what a produced'];
        unset($document['contentDigest']);

        $report = $this->verifier()->verify(ForensicBundle::fromArray($document));

        $failures = array_values(array_filter(
            $report->ofStatus(ForensicFinding::FAILED),
            static fn (ForensicFinding $f): bool => $f->check === ForensicVerifier::CHECK_ROUTING,
        ));

        $this->assertNotSame([], $failures);
        $this->assertSame('b', $failures[0]->nodeId);
    }

    public function test_a_redacted_bundle_reports_routing_as_unverifiable_not_as_fine(): void
    {
        // Redaction rewrites values, so a mismatch would say nothing about the
        // record and everything about the mask. Refusing to check is honest;
        // pretending to check is not.
        $this->migrateFlowTables();
        $this->seedRun();

        $bundle = $this->exporter()->export(self::RUN_ID, redact: true);
        $report = $this->verifier()->verify($bundle, $bundle->digest());

        $this->assertTrue($bundle->redacted);
        $this->assertTrue($report->intact());
        $unverifiable = array_map(static fn (ForensicFinding $f): string => $f->check, $report->ofStatus(ForensicFinding::UNVERIFIABLE));
        $this->assertContains(ForensicVerifier::CHECK_ROUTING, $unverifiable);
    }

    public function test_a_missing_expected_digest_is_unverifiable_rather_than_a_pass(): void
    {
        // Recomputing a digest and comparing it with itself always passes and
        // proves nothing.
        $this->migrateFlowTables();
        $this->seedRun();

        $report = $this->verifier()->verify($this->exporter()->export(self::RUN_ID, redact: false));

        $unverifiable = array_map(static fn (ForensicFinding $f): string => $f->check, $report->ofStatus(ForensicFinding::UNVERIFIABLE));

        $this->assertContains(ForensicVerifier::CHECK_DIGEST, $unverifiable);
        $this->assertTrue($report->intact(), '"unverifiable" is not a failure…');
        $this->assertGreaterThan(0, $report->counts()[ForensicFinding::UNVERIFIABLE], '…but it is counted, so nobody reads it as "fine".');
    }

    public function test_duplicate_sequence_numbers_mean_the_record_was_assembled(): void
    {
        $this->migrateFlowTables();
        $this->seedRun();

        $document = $this->exporter()->export(self::RUN_ID, redact: false)->toArray();
        $document['nodes'][1]['sequence'] = 1;
        unset($document['contentDigest']);

        $report = $this->verifier()->verify(ForensicBundle::fromArray($document));

        $failed = array_map(static fn (ForensicFinding $f): string => $f->check, $report->ofStatus(ForensicFinding::FAILED));

        $this->assertContains(ForensicVerifier::CHECK_SEQUENCE, $failed);
    }

    public function test_the_command_exports_and_verifies_a_file(): void
    {
        $this->migrateFlowTables();
        $this->seedRun();

        $path = tempnam(sys_get_temp_dir(), 'forensics-').'.json';

        try {
            $this->artisan('flow:forensics', ['runId' => self::RUN_ID, '--output' => $path, '--raw' => true])
                ->assertExitCode(0);

            $this->artisan('flow:forensics', ['--verify' => $path])
                ->expectsOutputToContain('Bundle intact')
                ->assertExitCode(0);

            // …and a single edited byte flips it.
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $document['run']['status'] = 'completed-but-actually-not';
            file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

            $this->artisan('flow:forensics', ['--verify' => $path])
                ->expectsOutputToContain('NOT intact')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    private function exporter(): ForensicExporter
    {
        return new ForensicExporter($this->app->make(PayloadRedactor::class));
    }

    private function verifier(): ForensicVerifier
    {
        return new ForensicVerifier($this->registry());
    }

    /**
     * The registry is a container singleton shared across a test, so registering
     * the fixture twice would throw; seeding and verifying both need it.
     */
    private function registry(): NodeRegistry
    {
        $registry = $this->app->make(NodeRegistry::class);

        if (! $registry->has('test.upper')) {
            $registry->register(UpperNode::class);
        }

        return $registry;
    }

    /**
     * A two-node run: `a` upper-cases a config string, `b` upper-cases what `a`
     * produced. Small on purpose — the routing replay only has something to
     * prove when one node's input actually comes from another's output.
     */
    private function seedRun(): void
    {
        $this->registry();

        $graph = new GraphDefinition(
            nodes: [
                new GraphNode('a', 'test.upper', ['text' => 'hello']),
                new GraphNode('b', 'test.upper'),
            ],
            connections: [new Connection('a', 'upper', 'b', 'text')],
        );

        $serializer = new GraphSerializer;

        FlowRunRecord::query()->create([
            'id' => self::RUN_ID,
            'definition_name' => 'echo-flow',
            'definition_version' => 1,
            'definition_checksum' => $serializer->checksum($graph),
            'graph' => $serializer->toArray($graph),
            'status' => 'completed',
            'dry_run' => false,
            'input' => ['seed' => 1],
            'output' => ['upper' => 'HELLO'],
            'engine' => 'graph',
            'nodes_total' => 2,
            'nodes_completed' => 2,
            'nodes_failed' => 0,
        ]);

        FlowRunNodeRecord::query()->create([
            'run_id' => self::RUN_ID, 'sequence' => 1, 'node_id' => 'a', 'node_type' => 'test.upper',
            'status' => 'completed', 'attempts' => 1,
            'inputs' => ['text' => 'hello'], 'outputs' => ['upper' => 'HELLO'],
            'dry_run_skipped' => false,
        ]);

        FlowRunNodeRecord::query()->create([
            'run_id' => self::RUN_ID, 'sequence' => 2, 'node_id' => 'b', 'node_type' => 'test.upper',
            'status' => 'completed', 'attempts' => 1,
            'inputs' => ['text' => 'HELLO'], 'outputs' => ['upper' => 'HELLO'],
            'dry_run_skipped' => false,
        ]);
    }
}
