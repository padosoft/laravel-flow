---
title: Gotchas and Limits
description: Known limits and operational warnings.
---

# Gotchas and Limits

::: callout warning "Know the boundary" icon:triangle-alert
laravel-flow is not a managed distributed workflow runtime. It is a Laravel package that coordinates workflows inside your app process and optional app database.
:::

## Common gotchas

- Dry-runs do not persist telemetry.
- The `array` cache store is not acceptable for approval resume or reject locks.
- Parallel compensation is only safe for independent, idempotent compensators.
- Closures are not accepted as durable handler definitions.
- Persisted payloads should be redacted before operators see them.
- Step names should not be casually renamed after persistence is enabled.

## Limits

Use Temporal, AWS Step Functions, or a similar service when the workflow must span languages, teams, long-lived distributed workers, or managed multi-region history.
