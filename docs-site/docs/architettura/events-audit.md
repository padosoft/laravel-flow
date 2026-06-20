---
title: Events and Audit
description: Laravel events and persisted audit behavior.
---

# Events and Audit

When `audit_trail_enabled` is true, laravel-flow dispatches lifecycle events for step and compensation transitions. With persistence enabled, audit rows are appended for non-dry-run executions.

```mermaid
sequenceDiagram
    participant Engine
    participant Audit as flow_audit
    participant Event as Laravel event bus
    Engine->>Audit: append step started
    Audit-->>Engine: durable
    Engine->>Event: FlowStepStarted
    Engine->>Audit: append step completed
    Audit-->>Engine: durable
    Engine->>Event: FlowStepCompleted
```

::: callout info "Failure behavior" icon:alert-circle
Normal step listener or persistence failures are surfaced after best-effort recovery and compensation. Compensation listener failures are swallowed after durable compensation audit so rollback is not interrupted.
:::

## Event classes

Core event classes include `FlowStepStarted`, `FlowStepCompleted`, `FlowStepFailed`, `FlowCompensated`, and `FlowPaused`.
