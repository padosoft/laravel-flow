---
title: Architecture Overview
description: High-level architecture of laravel-flow.
---

# Architecture Overview

laravel-flow has a small core with optional infrastructure adapters.

```mermaid
flowchart TB
    App[Laravel application] --> Facade[Flow facade]
    Facade --> Engine[FlowEngine]
    Engine --> Definition[FlowDefinition]
    Engine --> Handler[FlowStepHandler]
    Engine --> Compensator[FlowCompensator]
    Engine --> Events[Laravel events]
    Engine --> Store[FlowStore optional]
    Store --> DB[(App database)]
    Engine --> Queue[RunFlowJob optional]
    Engine --> Dashboard[Dashboard read model]
```

::: callout info "Headless by design" icon:monitor
The package exposes dashboard contracts but does not embed a UI. The companion admin panel can consume those contracts separately.
:::

## Layers

- Definition layer: fluent builder and immutable step definitions.
- Execution layer: input validation, handler resolution, step execution, compensation, and run result aggregation.
- Persistence layer: repository contracts with Eloquent implementations.
- Operations layer: Artisan commands, queued jobs, approvals, webhooks, pruning, replay, and dashboard DTOs.
