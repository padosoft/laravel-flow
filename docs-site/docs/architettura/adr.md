---
title: ADR
description: Architecture decision records for laravel-flow.
---

# ADR

::: collapsible "ADR-001: Dry-run is a first-class execution mode" open
Decision: dry-run is a mode of the same flow, not a separate preview flow.

Consequences: dry-run-aware steps run and can project business impact; other steps return skipped markers; dry-runs never persist telemetry.
:::

::: collapsible "ADR-002: Compensation walks backwards by default"
Decision: reverse-order saga compensation is the default strategy.

Consequences: rollback order is predictable and matches common dependency chains. Parallel compensation is opt-in for independent, idempotent compensators.
:::

::: collapsible "ADR-003: Handlers are classes, not closures"
Decision: handlers and compensators are container-resolved class names.

Consequences: queue serialization, dependency injection, static analysis, and stack traces are stronger than a closure-based API.
:::

::: collapsible "ADR-004: Persistence is opt-in"
Decision: in-memory synchronous execution remains the default.

Consequences: adoption is cheap for small workflows. Teams enable database persistence when they need durable runs, audit, approvals, webhooks, replay, or dashboard read models.
:::

::: collapsible "ADR-005: Dashboard surface is headless"
Decision: the package exposes read contracts and authorization hooks, not embedded UI.

Consequences: host applications control routing, auth, design, and deployment while consuming stable DTO contracts.
:::
