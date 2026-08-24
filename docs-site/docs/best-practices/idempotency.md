---
title: Idempotency
description: Use correlation and idempotency keys safely.
---

# Idempotency

Idempotency prevents repeated delivery from creating duplicated business effects. laravel-flow exposes execution options for correlation and idempotency metadata, and persisted successful step output can be reused during guarded scenarios.

## Run subject (who the run acts for)

Since v2.2 the same execution options carry an optional `subject` — the identity the run acts FOR (e.g. an IAM subject reference like `user:42`) when a run is started on behalf of someone: an agent-initiated run, a delegated tool call.

```php
Flow::execute('promotion.create', $input, FlowExecutionOptions::make(
    correlationId: 'checkout-2026-0001',
    idempotencyKey: 'tenant-42:promotion-abc',
    subject: 'user:42',
));
```

Rules that make it safe:

- `subject` is persisted on `flow_runs.subject` (nullable, indexed) and is **immutable after insert** — like the other identity fields, it is not in the run repository's updatable-column allow-list.
- Replays inherit the source run's subject unless the caller explicitly re-states it — on both `FlowEngine::replay()` and `flow:replay`.
- It is the **sanctioned home for run identity**: `flow_runs.input` is persisted unredacted, so identity (and tokens) must never travel through the run input.
- The dashboard read contract exposes it (`Dashboard\RunSummary::$subject`, exact-match `RunFilter::$subject`), so a companion dashboard can show and filter WHO each run acted for.

::: callout tip "Choose stable keys" icon:key
Use a domain identifier, such as an order id plus operation name, instead of a random UUID when the goal is deduplication.
:::

```php
use Padosoft\LaravelFlow\FlowExecutionOptions;

$options = new FlowExecutionOptions(
    correlationKey: 'order:123',
    idempotencyKey: 'order:123:fulfill',
);

$run = Flow::execute('order.fulfill', $input, $options);
```

## Handler rule

External calls should still use provider-level idempotency keys where supported. Flow-level idempotency does not automatically make a vendor API call idempotent.
