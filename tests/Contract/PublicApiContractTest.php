<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use Illuminate\Database\Eloquent\Builder;
use Padosoft\LaravelFlow\Dashboard\RunSummary;
use Padosoft\LaravelFlow\Node\PortProvenance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pin the v1.0 public API surface so a follow-up patch cannot silently
 * remove or rename a class, method, or constant marked `@api` in the
 * package source. Each helper assertion checks ReflectionClass against
 * a frozen expectation list.
 *
 * If you need to add a new public method to one of these classes, add
 * the method name to the corresponding expectation array. Removing or
 * renaming an existing entry requires a major version bump (see
 * docs/UPGRADE.md).
 */
final class PublicApiContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function apiClasses(): iterable
    {
        yield 'Flow facade' => ['Padosoft\\LaravelFlow\\Facades\\Flow'];
        yield 'FlowEngine' => ['Padosoft\\LaravelFlow\\FlowEngine'];
        yield 'FlowDefinitionBuilder' => ['Padosoft\\LaravelFlow\\FlowDefinitionBuilder'];
        yield 'FlowExecutionOptions' => ['Padosoft\\LaravelFlow\\FlowExecutionOptions'];
        yield 'FlowRun' => ['Padosoft\\LaravelFlow\\FlowRun'];
        yield 'FlowStepResult' => ['Padosoft\\LaravelFlow\\FlowStepResult'];
        yield 'FlowDefinition' => ['Padosoft\\LaravelFlow\\FlowDefinition'];
        yield 'FlowStep' => ['Padosoft\\LaravelFlow\\FlowStep'];
        yield 'FlowContext' => ['Padosoft\\LaravelFlow\\FlowContext'];
        yield 'DashboardReadScope' => ['Padosoft\\LaravelFlow\\Contracts\\DashboardReadScope'];
        yield 'FlowStepHandler' => ['Padosoft\\LaravelFlow\\FlowStepHandler'];
        yield 'FlowCompensator' => ['Padosoft\\LaravelFlow\\FlowCompensator'];
        yield 'IssuedApprovalToken' => ['Padosoft\\LaravelFlow\\IssuedApprovalToken'];
        yield 'ApprovalGate' => ['Padosoft\\LaravelFlow\\ApprovalGate'];
        yield 'ApprovalTokenManager' => ['Padosoft\\LaravelFlow\\ApprovalTokenManager'];
        yield 'WebhookDeliveryClient' => ['Padosoft\\LaravelFlow\\WebhookDeliveryClient'];
        yield 'WebhookDeliveryResult' => ['Padosoft\\LaravelFlow\\WebhookDeliveryResult'];

        yield 'FlowStepStarted event' => ['Padosoft\\LaravelFlow\\Events\\FlowStepStarted'];
        yield 'FlowStepCompleted event' => ['Padosoft\\LaravelFlow\\Events\\FlowStepCompleted'];
        yield 'FlowStepFailed event' => ['Padosoft\\LaravelFlow\\Events\\FlowStepFailed'];
        yield 'FlowCompensated event' => ['Padosoft\\LaravelFlow\\Events\\FlowCompensated'];
        yield 'FlowPaused event' => ['Padosoft\\LaravelFlow\\Events\\FlowPaused'];

        yield 'FlowException' => ['Padosoft\\LaravelFlow\\Exceptions\\FlowException'];
        yield 'FlowInputException' => ['Padosoft\\LaravelFlow\\Exceptions\\FlowInputException'];
        yield 'FlowExecutionException' => ['Padosoft\\LaravelFlow\\Exceptions\\FlowExecutionException'];
        yield 'FlowCompensationException' => ['Padosoft\\LaravelFlow\\Exceptions\\FlowCompensationException'];
        yield 'FlowNotRegisteredException' => ['Padosoft\\LaravelFlow\\Exceptions\\FlowNotRegisteredException'];
        yield 'ApprovalPersistenceException' => ['Padosoft\\LaravelFlow\\Exceptions\\ApprovalPersistenceException'];
        yield 'PersistenceUnavailableException' => ['Padosoft\\LaravelFlow\\Exceptions\\PersistenceUnavailableException'];

        yield 'FlowStore contract' => ['Padosoft\\LaravelFlow\\Contracts\\FlowStore'];
        yield 'RunRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\RunRepository'];
        yield 'RunNodeRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\RunNodeRepository'];
        yield 'NodeCacheRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\NodeCacheRepository'];
        yield 'AuditRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\AuditRepository'];
        yield 'ApprovalRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\ApprovalRepository'];
        yield 'ApprovalDecisionRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\ApprovalDecisionRepository'];
        yield 'ConditionalRunRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\ConditionalRunRepository'];
        yield 'BranchAwareRunNodeRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\BranchAwareRunNodeRepository'];
        yield 'TimerRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\TimerRepository'];
        yield 'PayloadRedactor contract' => ['Padosoft\\LaravelFlow\\Contracts\\PayloadRedactor'];
        yield 'CurrentPayloadRedactorProvider contract' => ['Padosoft\\LaravelFlow\\Contracts\\CurrentPayloadRedactorProvider'];
        yield 'RedactorAwareFlowStore contract' => ['Padosoft\\LaravelFlow\\Contracts\\RedactorAwareFlowStore'];
        yield 'RedactorAwareApprovalRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\RedactorAwareApprovalRepository'];
        yield 'FlowTrigger contract' => ['Padosoft\\LaravelFlow\\Contracts\\FlowTrigger'];

        yield 'FlowDashboardReadModel' => ['Padosoft\\LaravelFlow\\Dashboard\\FlowDashboardReadModel'];
        yield 'DashboardActionAuthorizer' => ['Padosoft\\LaravelFlow\\Dashboard\\Authorization\\DashboardActionAuthorizer'];
        yield 'AllowAllAuthorizer' => ['Padosoft\\LaravelFlow\\Dashboard\\Authorization\\AllowAllAuthorizer'];
        yield 'DenyAllAuthorizer' => ['Padosoft\\LaravelFlow\\Dashboard\\Authorization\\DenyAllAuthorizer'];
        yield 'RunSummary' => ['Padosoft\\LaravelFlow\\Dashboard\\RunSummary'];
        yield 'StepSummary' => ['Padosoft\\LaravelFlow\\Dashboard\\StepSummary'];
        yield 'AuditEntry' => ['Padosoft\\LaravelFlow\\Dashboard\\AuditEntry'];
        yield 'ApprovalSummary' => ['Padosoft\\LaravelFlow\\Dashboard\\ApprovalSummary'];
        yield 'WebhookOutboxSummary' => ['Padosoft\\LaravelFlow\\Dashboard\\WebhookOutboxSummary'];
        yield 'RunDetail' => ['Padosoft\\LaravelFlow\\Dashboard\\RunDetail'];
        yield 'RunFilter' => ['Padosoft\\LaravelFlow\\Dashboard\\RunFilter'];
        yield 'ApprovalFilter' => ['Padosoft\\LaravelFlow\\Dashboard\\ApprovalFilter'];
        yield 'WebhookOutboxFilter' => ['Padosoft\\LaravelFlow\\Dashboard\\WebhookOutboxFilter'];
        yield 'Pagination' => ['Padosoft\\LaravelFlow\\Dashboard\\Pagination'];
        yield 'PaginatedResult' => ['Padosoft\\LaravelFlow\\Dashboard\\PaginatedResult'];
        yield 'Kpis' => ['Padosoft\\LaravelFlow\\Dashboard\\Kpis'];

        yield 'ForensicBundle' => ['Padosoft\\LaravelFlow\\Forensics\\ForensicBundle'];
        yield 'ForensicExporter' => ['Padosoft\\LaravelFlow\\Forensics\\ForensicExporter'];
        yield 'ForensicVerifier' => ['Padosoft\\LaravelFlow\\Forensics\\ForensicVerifier'];
        yield 'ForensicReport' => ['Padosoft\\LaravelFlow\\Forensics\\ForensicReport'];
        yield 'ForensicFinding' => ['Padosoft\\LaravelFlow\\Forensics\\ForensicFinding'];
        yield 'RunNotExportableException' => ['Padosoft\\LaravelFlow\\Forensics\\RunNotExportableException'];

        yield 'PortProvenance' => ['Padosoft\\LaravelFlow\\Node\\PortProvenance'];
        yield 'TaintAnalyzer' => ['Padosoft\\LaravelFlow\\Provenance\\TaintAnalyzer'];
        yield 'TaintMap' => ['Padosoft\\LaravelFlow\\Provenance\\TaintMap'];
        yield 'TaintPath' => ['Padosoft\\LaravelFlow\\Provenance\\TaintPath'];
        yield 'TaintViolation' => ['Padosoft\\LaravelFlow\\Provenance\\TaintViolation'];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('apiClasses')]
    public function test_api_class_exists_and_is_marked_api(string $class): void
    {
        $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), sprintf('@api type [%s] is missing.', $class));

        $reflection = new ReflectionClass($class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment, sprintf('@api type [%s] is missing a class-level docblock.', $class));
        $this->assertStringContainsString('@api', (string) $docComment, sprintf(
            '@api type [%s] must declare the @api tag in its class docblock so SemVer guarantees apply.',
            $class,
        ));
    }

    public function test_flow_engine_pins_documented_public_methods(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\FlowEngine', [
            'define',
            'registerDefinition',
            'definitions',
            'definition',
            'execute',
            'dispatch',
            'dryRun',
            'resume',
            'reject',
            'resumeByHash',
            'rejectByHash',
            'redeliverWebhook',
            'cancel',
            'replay',
        ]);
    }

    public function test_flow_facade_pins_proxy_methods(): void
    {
        $reflection = new ReflectionClass('Padosoft\\LaravelFlow\\Facades\\Flow');
        $this->assertTrue($reflection->isSubclassOf('Illuminate\\Support\\Facades\\Facade'));
    }

    public function test_flow_run_pins_status_constants(): void
    {
        $this->assertHasConstants('Padosoft\\LaravelFlow\\FlowRun', [
            'STATUS_PENDING',
            'STATUS_RUNNING',
            'STATUS_PAUSED',
            'STATUS_SUCCEEDED',
            'STATUS_FAILED',
            'STATUS_COMPENSATED',
            'STATUS_ABORTED',
        ]);
    }

    public function test_flow_definition_builder_pins_documented_public_methods(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\FlowDefinitionBuilder', [
            'withInput',
            'step',
            'compensateWith',
            'approvalGate',
            'register',
        ]);
    }

    public function test_flow_step_result_pins_documented_factories(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\FlowStepResult', [
            'success',
            'failed',
            'dryRunSkipped',
            'paused',
        ]);
    }

    public function test_dashboard_read_model_pins_documented_public_methods(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Dashboard\\FlowDashboardReadModel', [
            'listRuns',
            'findRun',
            'stepCounts',
            'pendingApprovals',
            'listApprovals',
            'failedWebhookOutbox',
            'pendingWebhookOutbox',
            'listWebhookOutbox',
            'kpis',
            'withScope',
        ]);
    }

    public function test_dashboard_read_scope_pins_its_documented_public_method(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Contracts\\DashboardReadScope', [
            'apply',
        ]);
    }

    public function test_dashboard_read_scope_signature_is_pinned(): void
    {
        // The method NAME alone is not the contract: this interface exists so a
        // host can hand back a constrained builder, so narrowing the parameter
        // or widening the return would break every implementation while still
        // satisfying a name-only assertion.
        $method = (new ReflectionClass('Padosoft\\LaravelFlow\\Contracts\\DashboardReadScope'))->getMethod('apply');

        $this->assertCount(1, $method->getParameters());

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(Builder::class, $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $parameterType = $method->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $parameterType);
        $this->assertSame(Builder::class, $parameterType->getName());
    }

    public function test_step_summary_pins_read_properties(): void
    {
        $reflection = new ReflectionClass('Padosoft\\LaravelFlow\\Dashboard\\StepSummary');
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            // PUBLIC only — the pinned surface is the readable DTO contract,
            // so making any of these private/protected must fail this test.
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        foreach ([
            'id', 'runId', 'name', 'handler', 'sequence', 'status',
            'errorClass', 'errorMessage', 'durationMs', 'startedAt', 'finishedAt', 'cacheHit', 'activePorts', 'resumeAt',
        ] as $expected) {
            $this->assertContains(
                $expected,
                $properties,
                sprintf('StepSummary must expose the [%s] read property (@api).', $expected),
            );
        }
    }

    public function test_run_subject_surface_pins_read_properties(): void
    {
        // The run-subject feature (v2.2): WHO a run acts for, as first-class
        // @api surface — options in, run out, dashboard DTO through.
        foreach ([
            'Padosoft\\LaravelFlow\\FlowExecutionOptions' => ['correlationId', 'idempotencyKey', 'replayedFromRunId', 'subject'],
            'Padosoft\\LaravelFlow\\FlowRun' => ['subject'],
            'Padosoft\\LaravelFlow\\Dashboard\\RunSummary' => ['subject'],
            'Padosoft\\LaravelFlow\\Dashboard\\RunFilter' => ['subject'],
        ] as $class => $expectedProperties) {
            $reflection = new ReflectionClass($class);
            $properties = array_map(
                static fn (\ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
            );

            foreach ($expectedProperties as $expected) {
                $this->assertContains(
                    $expected,
                    $properties,
                    sprintf('%s must expose the [%s] read property (@api).', $class, $expected),
                );
            }
        }
    }

    public function test_run_summary_pre_subject_positional_arity_still_constructs(): void
    {
        // BC guard for v2.2.1: `subject` was FIRST added mid-constructor without a
        // default, breaking every positional 13-arg construction shipped before
        // v2.2 (flow-ai's Advisor tests, flow-admin's read model). New DTO params
        // must be TRAILING with a default — this pins that a pre-2.2 positional
        // construction keeps working.
        $summary = new RunSummary(
            'run-1', 'flow.def', 'succeeded', false, null, false, null,
            'corr-1', 'idem-1', null, 12, null, null,
        );

        $this->assertNull($summary->subject);
        $this->assertSame('corr-1', $summary->correlationId);
    }

    public function test_dashboard_authorizer_pins_documented_methods(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Dashboard\\Authorization\\DashboardActionAuthorizer', [
            'canViewRuns',
            'canViewRunDetail',
            'canReplayRun',
            'canApproveByToken',
            'canRejectByToken',
            'canViewKpis',
        ]);
    }

    public function test_approval_token_manager_exposes_hash_helper(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\ApprovalTokenManager', [
            'hashToken',
            'issue',
            'find',
            'findByHash',
            'pending',
            'approve',
            'reject',
            'approveForRunStatusByHash',
            'rejectForRunStatusByHash',
        ]);
    }

    public function test_run_node_repository_pins_documented_methods(): void
    {
        // `states` and `claim` were added in Macro C C-PR5 for the queued
        // coordinator; pin them alongside the v1-parity persistence methods.
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Contracts\\RunNodeRepository', [
            'createOrUpdate',
            'forRun',
            'states',
            'claim',
            'releaseClaim',
            'terminate',
        ]);
    }

    public function test_node_cache_repository_pins_documented_methods(): void
    {
        // Content-hash node cache contract added in Macro C C-PR7.
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Contracts\\NodeCacheRepository', [
            'find',
            'put',
        ]);
    }

    public function test_flow_trigger_pins_fire_signature(): void
    {
        // Trigger contract added in Macro D D-PR2: lives in CORE (not the
        // satellite laravel-flow-connect package) so it is a stable,
        // SemVer-covered surface any trigger source can depend on.
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Contracts\\FlowTrigger', [
            'fire',
        ]);

        $method = (new ReflectionClass('Padosoft\\LaravelFlow\\Contracts\\FlowTrigger'))->getMethod('fire');
        $parameters = $method->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertSame('flowName', $parameters[0]->getName());
        $this->assertFalse($parameters[0]->isOptional());
        $this->assertSame('input', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isOptional());
        $this->assertSame('options', $parameters[2]->getName());
        $this->assertTrue($parameters[2]->isOptional());
        $this->assertNull($parameters[2]->getDefaultValue());

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function test_internal_namespaces_are_marked_internal(): void
    {
        $internalClasses = [
            'Padosoft\\LaravelFlow\\Persistence\\EloquentFlowStore',
            'Padosoft\\LaravelFlow\\Persistence\\EloquentRunRepository',
            'Padosoft\\LaravelFlow\\Persistence\\EloquentApprovalRepository',
            'Padosoft\\LaravelFlow\\Persistence\\EloquentWebhookOutboxRepository',
            'Padosoft\\LaravelFlow\\Persistence\\KeyBasedPayloadRedactor',
            'Padosoft\\LaravelFlow\\Persistence\\ExecutionScopedPayloadRedactor',
            'Padosoft\\LaravelFlow\\Persistence\\FlowPruner',
            'Padosoft\\LaravelFlow\\Models\\FlowRunRecord',
            'Padosoft\\LaravelFlow\\Models\\FlowRunNodeRecord',
            'Padosoft\\LaravelFlow\\Models\\FlowAuditRecord',
            'Padosoft\\LaravelFlow\\Models\\FlowApprovalRecord',
            'Padosoft\\LaravelFlow\\Models\\FlowWebhookOutboxRecord',
            'Padosoft\\LaravelFlow\\Jobs\\RunFlowJob',
            'Padosoft\\LaravelFlow\\Queue\\QueueRetryPolicy',
            'Padosoft\\LaravelFlow\\Console\\PruneFlowRunsCommand',
            'Padosoft\\LaravelFlow\\Console\\ReplayFlowRunCommand',
            'Padosoft\\LaravelFlow\\Console\\ApproveFlowCommand',
            'Padosoft\\LaravelFlow\\Console\\RejectFlowCommand',
            'Padosoft\\LaravelFlow\\Console\\DeliverWebhookOutboxCommand',
            'Padosoft\\LaravelFlow\\Console\\ForensicsCommand',
            'Padosoft\\LaravelFlow\\Console\\TaintCommand',
            'Padosoft\\LaravelFlow\\Forensics\\ForensicCanonicalJson',
        ];

        foreach ($internalClasses as $class) {
            $this->assertTrue(class_exists($class), sprintf('@internal class [%s] is missing.', $class));
            $docComment = (new ReflectionClass($class))->getDocComment();
            $this->assertNotFalse($docComment, sprintf('@internal class [%s] is missing a class-level docblock.', $class));
            $this->assertStringContainsString('@internal', (string) $docComment, sprintf(
                'Class [%s] is in an internal namespace and must declare @internal in its class docblock.',
                $class,
            ));
        }
    }

    public function test_forensic_bundle_pins_documented_surface(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Forensics\\ForensicBundle', [
            'body',
            'toArray',
            'digest',
        ]);

        $this->assertHasConstants('Padosoft\\LaravelFlow\\Forensics\\ForensicBundle', [
            'FORMAT',
            'SPEC_VERSION',
        ]);
    }

    public function test_forensic_exporter_and_verifier_pin_documented_surface(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Forensics\\ForensicExporter', ['export']);
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Forensics\\ForensicVerifier', ['verify']);

        // The four checks are the contract a consumer writes assertions
        // against; renaming one silently changes what a report means.
        $this->assertHasConstants('Padosoft\\LaravelFlow\\Forensics\\ForensicVerifier', [
            'CHECK_DIGEST',
            'CHECK_DEFINITION',
            'CHECK_SEQUENCE',
            'CHECK_ROUTING',
        ]);
    }

    public function test_forensic_report_and_finding_pin_documented_surface(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Forensics\\ForensicReport', [
            'intact',
            'ofStatus',
            'counts',
            'toArray',
        ]);

        // `unverifiable` is a first-class third state, not a flavour of
        // `failed` — a consumer branching on two statuses is a bug.
        $this->assertHasConstants('Padosoft\\LaravelFlow\\Forensics\\ForensicFinding', [
            'OK',
            'FAILED',
            'UNVERIFIABLE',
        ]);
    }

    public function test_taint_analysis_pins_documented_surface(): void
    {
        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Provenance\\TaintAnalyzer', [
            'analyze',
            'violations',
        ]);

        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Provenance\\TaintMap', [
            'inputIsUntrusted',
            'outputIsUntrusted',
            'pathToInput',
            'pathToOutput',
            'untrustedPorts',
            'toArray',
        ]);

        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Provenance\\TaintPath', [
            'source',
            'then',
            'origin',
            'render',
        ]);

        $this->assertHasPublicMethods('Padosoft\\LaravelFlow\\Provenance\\TaintViolation', [
            'message',
            'toArray',
        ]);
    }

    public function test_port_provenance_pins_its_three_cases(): void
    {
        // A graph author writes these names in a node attribute, and a
        // stored catalog projection serialises their values. Renaming one
        // silently reclassifies data that was already labelled.
        $cases = array_map(
            static fn (PortProvenance $case): string => $case->value,
            PortProvenance::cases(),
        );

        sort($cases);

        $this->assertSame(['derived', 'trusted', 'untrusted'], $cases);
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $methods
     */
    private function assertHasPublicMethods(string $class, array $methods): void
    {
        $reflection = new ReflectionClass($class);
        $publicMethods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $publicMethods[] = $method->getName();
        }

        foreach ($methods as $method) {
            $this->assertContains($method, $publicMethods, sprintf(
                '%s::%s() is part of the v1.0 @api surface and must remain a public method.',
                $class,
                $method,
            ));
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $constants
     */
    private function assertHasConstants(string $class, array $constants): void
    {
        $reflection = new ReflectionClass($class);
        foreach ($constants as $constant) {
            $this->assertTrue($reflection->hasConstant($constant), sprintf(
                '%s::%s is part of the v1.0 @api surface and must remain a public constant.',
                $class,
                $constant,
            ));
        }
    }
}
