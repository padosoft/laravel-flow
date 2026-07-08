<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\ApprovalGate;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyStepNodeAdapterTest extends TestCase
{
    private function context(array $inputs, bool $dryRun = false): NodeContext
    {
        return new NodeContext('run-9', 'legacy-demo', 'node-9', $inputs, $dryRun);
    }

    public function test_definition_exposes_json_in_out_ports(): void
    {
        $step = new class implements FlowStepHandler
        {
            public function execute(FlowContext $context): FlowStepResult
            {
                return FlowStepResult::success();
            }
        };

        $definition = LegacyStepNodeAdapter::definitionFor('legacy.demo', $step::class);

        $this->assertSame('legacy.demo', $definition->type);
        $this->assertSame(PortType::Json, $definition->input('input')?->type);
        $this->assertFalse($definition->input('input')->required);
        $this->assertSame(PortType::Json, $definition->output('output')?->type);
        $this->assertSame($step::class, $definition->handlerClass);
    }

    public function test_definition_name_for_namespaced_and_global_classes(): void
    {
        require_once __DIR__.'/../../Fixtures/GlobalNamespaceLegacyStep.php';

        $namespaced = LegacyStepNodeAdapter::definitionFor('legacy.ns', ApprovalGate::class);
        $this->assertSame('ApprovalGate', $namespaced->name);

        $global = LegacyStepNodeAdapter::definitionFor('legacy.global', \GlobalNamespaceLegacyStep::class);
        $this->assertSame('GlobalNamespaceLegacyStep', $global->name);
    }

    public function test_definition_for_rejects_blank_node_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type must not be empty/i');

        LegacyStepNodeAdapter::definitionFor('  ', ApprovalGate::class);
    }

    public function test_definition_for_rejects_nonexistent_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');

        LegacyStepNodeAdapter::definitionFor('legacy.missing', 'App\\Does\\Not\\ExistStep');
    }

    public function test_definition_for_rejects_non_step_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must implement/i');

        LegacyStepNodeAdapter::definitionFor('legacy.notstep', FlowContext::class);
    }

    public function test_success_maps_output_and_impact_and_context(): void
    {
        $step = new class implements FlowStepHandler
        {
            public ?FlowContext $seen = null;

            public function execute(FlowContext $context): FlowStepResult
            {
                $this->seen = $context;

                return FlowStepResult::success(['total' => 7], ['orders' => 1]);
            }
        };

        $result = (new LegacyStepNodeAdapter($step))->execute($this->context(['input' => ['sku' => 'A1']], dryRun: true));

        $this->assertTrue($result->success);
        $this->assertSame(['output' => ['total' => 7]], $result->outputs);
        $this->assertSame(['orders' => 1], $result->businessImpact);
        $this->assertSame('run-9', $step->seen->flowRunId);
        $this->assertSame(['sku' => 'A1'], $step->seen->input);
        $this->assertTrue($step->seen->dryRun);
        $this->assertSame([], $step->seen->stepOutputs);
    }

    public function test_failure_and_control_results_map_one_to_one(): void
    {
        $error = new RuntimeException('legacy boom');
        $step = new class($error) implements FlowStepHandler
        {
            public function __construct(private readonly \Throwable $error) {}

            public function execute(FlowContext $context): FlowStepResult
            {
                return match ($context->input['mode']) {
                    'fail' => FlowStepResult::failed($this->error),
                    'skip' => FlowStepResult::dryRunSkipped(),
                    default => FlowStepResult::paused(['token' => 't']),
                };
            }
        };
        $adapter = new LegacyStepNodeAdapter($step);

        $failed = $adapter->execute($this->context(['input' => ['mode' => 'fail']]));
        $this->assertFalse($failed->success);
        $this->assertSame($error, $failed->error);

        $skipped = $adapter->execute($this->context(['input' => ['mode' => 'skip']]));
        $this->assertTrue($skipped->dryRunSkipped);

        $paused = $adapter->execute($this->context(['input' => ['mode' => 'pause']]));
        $this->assertTrue($paused->paused);
        $this->assertSame(['output' => ['token' => 't']], $paused->outputs);
    }

    public function test_missing_input_port_defaults_to_empty_array(): void
    {
        $step = new class implements FlowStepHandler
        {
            public function execute(FlowContext $context): FlowStepResult
            {
                return FlowStepResult::success(['echo' => $context->input]);
            }
        };

        $result = (new LegacyStepNodeAdapter($step))->execute($this->context([]));

        $this->assertSame(['output' => ['echo' => []]], $result->outputs);
    }

    public function test_thrown_step_failure_maps_to_failed_result(): void
    {
        $error = new RuntimeException('v1 throw-to-fail');
        $step = new class($error) implements FlowStepHandler
        {
            public function __construct(private readonly \Throwable $error) {}

            public function execute(FlowContext $context): FlowStepResult
            {
                throw $this->error;
            }
        };

        $result = (new LegacyStepNodeAdapter($step))->execute($this->context(['input' => []]));

        $this->assertFalse($result->success);
        $this->assertSame($error, $result->error);
    }

    public function test_non_array_input_payload_fails_without_reaching_the_step(): void
    {
        $step = new class implements FlowStepHandler
        {
            public bool $called = false;

            public function execute(FlowContext $context): FlowStepResult
            {
                $this->called = true;

                return FlowStepResult::success();
            }
        };

        $result = (new LegacyStepNodeAdapter($step))->execute($this->context(['input' => 'not-an-array']));

        $this->assertFalse($result->success);
        $this->assertFalse($step->called);
        $this->assertStringContainsString('array payload', (string) $result->error?->getMessage());
    }
}
