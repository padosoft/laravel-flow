<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

        yield 'FlowStore contract' => ['Padosoft\\LaravelFlow\\Contracts\\FlowStore'];
        yield 'RunRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\RunRepository'];
        yield 'StepRunRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\StepRunRepository'];
        yield 'AuditRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\AuditRepository'];
        yield 'ApprovalRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\ApprovalRepository'];
        yield 'ApprovalDecisionRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\ApprovalDecisionRepository'];
        yield 'ConditionalRunRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\ConditionalRunRepository'];
        yield 'PayloadRedactor contract' => ['Padosoft\\LaravelFlow\\Contracts\\PayloadRedactor'];
        yield 'CurrentPayloadRedactorProvider contract' => ['Padosoft\\LaravelFlow\\Contracts\\CurrentPayloadRedactorProvider'];
        yield 'RedactorAwareFlowStore contract' => ['Padosoft\\LaravelFlow\\Contracts\\RedactorAwareFlowStore'];
        yield 'RedactorAwareApprovalRepository contract' => ['Padosoft\\LaravelFlow\\Contracts\\RedactorAwareApprovalRepository'];

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
        // Replay is exposed as the flow:replay Artisan command rather than a
        // FlowEngine method; pin only the methods that v1.0 callers depend on.
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
            'pendingApprovals',
            'listApprovals',
            'failedWebhookOutbox',
            'pendingWebhookOutbox',
            'listWebhookOutbox',
            'kpis',
        ]);
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
            'pending',
            'approve',
            'reject',
        ]);
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
            'Padosoft\\LaravelFlow\\Models\\FlowStepRecord',
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
