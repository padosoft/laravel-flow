# Migrating from `symfony/workflow`

This guide maps `symfony/workflow` (the Symfony Workflow component) and its Laravel ports (e.g. `brexis/laravel-workflow` style packages) to `padosoft/laravel-flow` primitives. Use it when your existing workflow is a state-machine over a single domain object and you want compensation, persisted runs, queued execution, approval gates, audit, and webhook delivery without inventing those layers yourself.

> **Scope.** Symfony Workflow models a state machine attached to a `Subject` (typically an Eloquent model or domain object) and gates `transitions` through guards. Laravel Flow models a sequence of steps over a `FlowContext` plus business impact, with compensation and approvals as first-class primitives. The two have different mental models: Symfony Workflow tracks *which state the subject is in*, Laravel Flow tracks *which step in the recipe ran and what it produced*. Migration is straightforward for linear or branching workflows; complex multi-place state machines (Petri nets) need redesign.

## Concept mapping

| Symfony Workflow concept | Laravel Flow equivalent | Notes |
| --- | --- | --- |
| `Workflow` definition (places + transitions) | `FlowDefinition` registered via `Flow::define(...)->step(...)->register()` | Linear ordered steps; for branching use multiple definitions plus `Flow::dispatch()` glue. |
| `Subject` (the entity holding state) | `FlowContext` carrying input + intermediate step results | The subject is the input to step 1 and is referenced via context throughout. |
| `Transition` | `FlowStep` registered with a handler class | Each transition becomes one step. |
| Guard event | Pre-step validation in the handler itself + `withInput([...])` strict input validation | Guards become explicit handler checks that throw `FlowExecutionException`. |
| Marking store (in-property / in-DB) | `flow_runs.status` plus persisted step rows | Persistence is opt-in (`persistence.enabled=true`). |
| Audit / `Workflow::apply` event listeners | `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated`, `FlowPaused` events plus `flow_audit` rows | Events fire normally when `audit_trail_enabled=true`. |
| Manual approvals (transition guards on user role) | `approvalGate($name)` + `Flow::resume()` / `Flow::reject()` | First-class with hashed one-time tokens. |
| Reverting a transition | `compensateWith(MyCompensator::class)` | Default reverse-order rollback. |
| Workflow visualization | `FlowDashboardReadModel::findRun($id)` returns ordered steps + audit | The companion app renders the timeline. |
| Multi-place transitions / Petri net | Not supported as a single definition | Either flatten into a linear sequence or split into multiple flows linked by application code. |

## Step-by-step migration

1. **Rewrite the workflow definition.** Convert the Symfony YAML / array config to a `Flow::define()` call:

   Symfony:

   ```yaml
   framework:
       workflows:
           order:
               type: state_machine
               supports: App\Entity\Order
               places: [draft, paid, shipped, delivered]
               transitions:
                   pay: { from: draft, to: paid }
                   ship: { from: paid, to: shipped }
                   deliver: { from: shipped, to: delivered }
   ```

   Laravel Flow:

   ```php
   Flow::define('order')
       ->withInput(['order_id'])
       ->step('pay', PayOrder::class)->compensateWith(RefundPayment::class)
       ->step('ship', ShipOrder::class)->compensateWith(CancelShipment::class)
       ->step('deliver', DeliverOrder::class)
       ->register();
   ```

2. **Translate guards into handler-side checks.** Where Symfony used a `workflow.order.guard.pay` listener to deny the transition based on role, do the check inside the handler or wrap the dispatch:

   ```php
   public function handle(FlowContext $context): FlowStepResult
   {
       if (! $this->billing->canCharge($context->input['order_id'])) {
           throw new FlowExecutionException('Cannot charge order in current state.');
       }

       // ... charge logic ...
   }
   ```

3. **Replace marking stores.** Symfony's marking store (whether a property on the subject or a DB table) is replaced by `flow_runs.status` once persistence is enabled. Drop the marking column on your subject Eloquent model and read status from the run record instead.

4. **Replace `apply()` calls** with `Flow::execute()` for synchronous progression or `Flow::dispatch()` for queued progression:

   ```php
   // Symfony
   $workflow->apply($order, 'pay');

   // Laravel Flow (synchronous)
   Flow::execute('order', ['order_id' => $order->id]);

   // Laravel Flow (queued)
   Flow::dispatch('order', ['order_id' => $order->id]);
   ```

5. **Migrate event listeners.** Symfony Workflow events map to Laravel Flow events:

   | Symfony event | Laravel Flow event |
   | --- | --- |
   | `workflow.<name>.entered.<place>` | `FlowStepCompleted` (matching step name) |
   | `workflow.<name>.transition.<transition>` | `FlowStepStarted` (matching step name) |
   | `workflow.<name>.guard.<transition>` | Pre-step handler logic (see step 2) |
   | `workflow.<name>.completed.<transition>` | `FlowStepCompleted` |
   | `workflow.<name>.entered` (any place) | `FlowStepCompleted` (any step) |

   Update your listeners to type-hint `FlowStepCompleted` (or the appropriate event) and re-read step name from `$event->stepName`.

6. **Approval flows.** Where Symfony used a guard backed by a "manual approval" UI, replace with `approvalGate($name)`:

   ```php
   Flow::define('order-with-review')
       ->step('pay', PayOrder::class)->compensateWith(RefundPayment::class)
       ->approvalGate('finance-review')
       ->step('ship', ShipOrder::class)
       ->register();
   ```

   When the gate fires, persist the plain token (`$run->approvalTokens['finance-review']->plainTextToken`) out-of-band to the operator (email/Slack) — it is never recoverable from storage.

7. **Audit replacement.** Drop bespoke audit tables that mirror transitions; rely on `flow_audit` rows surfaced through `FlowDashboardReadModel::findRun()->audit`.

8. **Visualization.** Symfony has `php bin/console workflow:dump` for graph generation. Laravel Flow does not ship a graph generator; the companion dashboard app at `docs/DASHBOARD_APP_SPEC.md` renders the persisted timeline instead. If you need a static graph, generate it from your `FlowDefinition` registration code.

## Things that don't translate cleanly

- **Petri nets / parallel places.** Symfony Workflow supports multiple active places via Petri-net workflows. Laravel Flow's engine is sequential. If you rely on Petri nets, redesign as multiple linear flows linked by application code or use compensation strategies.
- **Subject-coupled marking.** Symfony stores the marking on the subject. Laravel Flow keeps state on `flow_runs`, not on your domain model. Search by `correlation_id` or `idempotency_key` to bridge the gap.
- **Built-in form-based transition UI.** Symfony's `EnumTransitionType` form helpers do not have an equivalent in Laravel Flow; the companion app spec describes how to build operator transitions inside the dashboard.

## Trade-offs

| Capability | symfony/workflow | Laravel Flow |
| --- | --- | --- |
| State-machine modeling | ✅ First-class places/transitions | ⚠️ Linear sequence with branching via dispatch |
| Petri-net workflows | ✅ Supported | ❌ Not supported |
| Compensation chains | ❌ Manual via listeners | ✅ First-class `compensateWith` |
| Approval gates | ❌ Build it yourself | ✅ `approvalGate()` + tokenized resume/reject |
| Persisted run history | ⚠️ Marking + custom audit | ✅ `flow_runs` + `flow_steps` + `flow_audit` |
| Webhook outbox | ❌ Not provided | ✅ `flow_webhook_outbox` + signed delivery |
| Visualization | ✅ `workflow:dump` | ⚠️ Companion dashboard app |
| Container integration | ✅ Symfony DI | ✅ Laravel container |

## See also

- [`docs/UPGRADE.md`](UPGRADE.md) — within-package upgrade guidance.
- [`docs/MIGRATION_DURABLE.md`](MIGRATION_DURABLE.md) — moving from a durable-workflow runtime.
- [`docs/DASHBOARD_APP_SPEC.md`](DASHBOARD_APP_SPEC.md) — companion app brief.
- [`README.md`](../README.md) — feature reference, comparison table, configuration.
