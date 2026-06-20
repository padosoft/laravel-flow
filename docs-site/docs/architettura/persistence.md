---
title: Persistence
description: Optional database persistence for laravel-flow.
---

# Persistence

Persistence is disabled by default. When enabled, non-dry-run executions can write runs, steps, audit rows, approvals, and webhook outbox rows.

::: callout warning "Dry-run exception" icon:database
Dry-runs never persist run, step, or audit rows. This remains true even when persistence is enabled.
:::

## Tables

- `flow_runs`: run identity, status, input, output, business impact, correlation key, idempotency key, replay lineage.
- `flow_steps`: per-step status and payload.
- `flow_audit`: append-only transition log during normal runtime.
- `flow_approvals`: hashed one-time approval tokens and decisions.
- `flow_webhook_outbox`: signed lifecycle delivery queue.

## Custom stores

Implement `FlowStore` and repository contracts when the default Eloquent implementation is not enough. Implement `RedactorAwareFlowStore` if custom persistence needs the same execution-scoped `PayloadRedactor` used by the engine.
