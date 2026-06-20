---
title: API
description: Public API reference.
---

# API

The public API is marked with `@api` in source and pinned by `tests/Contract/PublicApiContractTest`.

## Facade

- `Flow::define($name)`
- `Flow::execute($name, $input, $options = null)`
- `Flow::dryRun($name, $input, $options = null)`
- `Flow::dispatch($name, $input, $options = null)`
- `Flow::resume($token, $payload, $actor)`
- `Flow::reject($token, $payload, $actor)`

## Core classes

- `FlowEngine`
- `FlowDefinitionBuilder`
- `FlowDefinition`
- `FlowStep`
- `FlowExecutionOptions`
- `FlowRun`
- `FlowStepResult`
- `FlowContext`
- `ApprovalGate`
- `ApprovalTokenManager`
- `IssuedApprovalToken`
- `WebhookDeliveryClient`
- `WebhookDeliveryResult`

::: callout warning "Internal namespaces" icon:package-x
Classes under persistence, models, queue, jobs, and console namespaces are implementation details unless explicitly marked `@api`.
:::
