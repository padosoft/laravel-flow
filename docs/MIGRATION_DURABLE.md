# Migrating from Durable Workflow PHP libraries

This guide maps concepts from common PHP "durable workflow" libraries (e.g. Temporal PHP SDK, Bytestgang Durable Workflow style packages) to `padosoft/laravel-flow` primitives. It is intended to help teams replace an existing durable workflow integration with Laravel Flow when the dominant requirements are persisted runs, compensation chains, approvals, audit trails, and signed webhook delivery — and when the team prefers to stay inside Laravel's application boundary instead of running a dedicated workflow service.

> **Scope.** Laravel Flow is **not** a drop-in replacement for Temporal Cloud or a similar managed workflow runtime. It deliberately runs inside the host Laravel application, persisting durable state to the host database, with no separate workflow worker cluster. If your existing workflow uses retry-storms across days, distributed timers, or large-scale fan-out across hundreds of activity workers, evaluate whether Laravel Flow's queue-backed engine fits before migrating.

## Concept mapping

| Durable workflow concept | Laravel Flow equivalent | Notes |
| --- | --- | --- |
| Workflow definition | `FlowDefinition` registered via `Flow::define($name)->step(...)->register()` | Definitions live in PHP code in your Laravel app, not in a separate workflow service. |
| Activity | `FlowStepHandler` invoked from a step | Each step resolves a handler through Laravel's container. |
| Compensation / saga rollback | `compensateWith(MyCompensator::class)` per step plus `compensation_strategy=reverse-order` (default) or `parallel` | Reverse-order is the safe default; `parallel` is opt-in for independent compensators. |
| Workflow run / execution | `FlowRun` returned by `Flow::execute()` or `Flow::dispatch()` | Persisted in `flow_runs` when `persistence.enabled=true`. |
| Activity input / output | `FlowContext` and `FlowStepResult` | Inputs flow through `FlowContext`; results carry output and `businessImpact`. |
| Signal / external event | `approvalGate($name)` step type, then `Flow::resume($plainToken)` or `Flow::reject($plainToken)` | Approval tokens are SHA-256 hashed at rest; the plain token is returned only on the immediate `FlowRun`. |
| Workflow status query | `FlowDashboardReadModel::findRun($id)` returns `RunDetail` | Read-only contract for dashboard / monitoring. |
| Continue-as-new / replay | `php artisan flow:replay {runId}` | Creates a new linked run with `replayed_from_run_id`; the original row is not mutated. |
| Workflow events / history | `flow_audit` rows + Laravel events `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated`, `FlowPaused` | Audit rows are append-only at runtime and only deletable via `flow:prune`. |
| Heartbeat / activity timeout | `webhook.timeout_seconds` (HTTP delivery only) | The package does not implement step-level heartbeats; long-running steps run synchronously inside the queue worker. |
| External system delivery | Webhook outbox (`flow_webhook_outbox`) drained by `flow:deliver-webhooks` | HMAC-SHA256 signed payloads, exponential retry, configurable max attempts. |
| Cron / scheduled workflows | Use Laravel's scheduler to call `Flow::dispatch()` | Not part of this package. |
| Workflow worker | Laravel queue worker (sync or database queue) | Configure `queue.lock_store` to a shared cache for multi-worker setups. |
| Idempotency key | `FlowExecutionOptions::make(idempotencyKey: ...)` | Synchronous `Flow::execute()` reuses an existing persisted run for the same key + definition. |
| Correlation id | `FlowExecutionOptions::make(correlationId: ...)` | Stored on `flow_runs.correlation_id`; surfaced through the dashboard read model. |

## Step-by-step migration

1. **Map your activities to step handlers.** Each activity becomes a `FlowStepHandler` class with a `handle(FlowContext $context): FlowStepResult` method.

   ```php
   final class ChargeCard implements FlowStepHandler
   {
       public function handle(FlowContext $context): FlowStepResult
       {
           $result = $this->gateway->charge($context->input['amount']);

           return FlowStepResult::ok(
               output: ['charge_id' => $result->id],
               businessImpact: ['amount_cents' => $result->amount],
           );
       }
   }
   ```

2. **Map compensations.** Each step that needs rollback gets a `FlowCompensator`:

   ```php
   final class RefundCard implements FlowCompensator
   {
       public function compensate(FlowContext $context): void
       {
           $this->gateway->refund($context->results['ChargeCard']->output['charge_id']);
       }
   }
   ```

3. **Define the workflow.** Move your durable workflow definition into a `FlowServiceProvider::boot()`:

   ```php
   Flow::define('checkout')
       ->withInput(['order_id', 'amount'])
       ->step('charge', ChargeCard::class)->compensateWith(RefundCard::class)
       ->step('ship', SchedulePickup::class)->compensateWith(CancelPickup::class)
       ->register();
   ```

4. **Replace signals with approval gates** if the workflow needs operator sign-off:

   ```php
   Flow::define('checkout-with-approval')
       ->step('charge', ChargeCard::class)->compensateWith(RefundCard::class)
       ->approvalGate('manager-review')
       ->step('ship', SchedulePickup::class)->compensateWith(CancelPickup::class)
       ->register();
   ```

5. **Run the workflow.** Replace your existing trigger with one of:

   ```php
   $run = Flow::execute('checkout', ['order_id' => 1, 'amount' => 5000]); // synchronous
   $job = Flow::dispatch('checkout', ['order_id' => 1, 'amount' => 5000]); // queued
   ```

6. **Migrate signals.** Replace `WorkflowClient::signal()` calls with `Flow::resume($plainToken, $payload)` or `Flow::reject($plainToken, $payload)`.

7. **Migrate observability.** Replace your durable workflow dashboard with the package read service:

   ```php
   $reader = app(FlowDashboardReadModel::class);
   $page = $reader->listRuns(new RunFilter(status: 'failed'), new Pagination(1, 25));
   ```

   For UI, use the companion app described in [`DASHBOARD_APP_SPEC.md`](DASHBOARD_APP_SPEC.md).

8. **Migrate event subscribers.** Replace external workflow event handlers with Laravel event listeners on `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowPaused`, `FlowCompensated`. Keep listeners idempotent — they run synchronously inside the engine transaction.

9. **Decide on retention.** Schedule `flow:prune` if you need to bound the audit table:

   ```php
   $schedule->command('flow:prune --days=90')->dailyAt('02:00');
   ```

## Trade-offs vs. an external workflow service

| Capability | External durable runtime | Laravel Flow |
| --- | --- | --- |
| Cross-language workflows | ✅ Yes (gRPC SDKs) | ❌ PHP-only handlers in the host app |
| Multi-region failover | ✅ Yes (managed cluster) | ❌ Single host DB, single cache |
| Long-running timers (days/weeks) | ✅ First-class timer service | ⚠️ Use Laravel scheduler + idempotency keys |
| In-process speed | ⚠️ Network hop per activity | ✅ Direct container resolution |
| Operational footprint | ❌ Separate cluster | ✅ Same Laravel app, same DB |
| Cost | ⚠️ Managed service or operating a cluster | ✅ Just the host DB and queue |

If long-duration timers and language-agnostic activities are critical, stay on the external runtime. If the workflow is PHP-only and lives next to the host application data, migrating to Laravel Flow removes a service from your stack.

## Things this guide cannot do for you

- Keep historical workflow event history when migrating live workflows. Plan a draining window: stop new workflows on the old engine, wait for in-flight workflows to terminate, then switch over.
- Bridge a Temporal-style retry policy across days. Re-design those flows around `Flow::dispatch()` + scheduled `Flow::execute()` re-triggers + idempotency keys.
- Replicate a fully cross-tenant managed UI. Use the companion dashboard app spec to build a dashboard tailored to your auth model.

## See also

- [`docs/UPGRADE.md`](UPGRADE.md) — within-package upgrade guidance.
- [`docs/DASHBOARD_APP_SPEC.md`](DASHBOARD_APP_SPEC.md) — companion app brief.
- [`README.md`](../README.md) — feature reference, comparison table, configuration.
