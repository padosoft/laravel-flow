---
title: Events
description: Event reference for laravel-flow.
---

# Events

| Event | Meaning |
| --- | --- |
| `FlowStepStarted` | A step started execution. |
| `FlowStepCompleted` | A step completed successfully. |
| `FlowStepFailed` | A step failed. |
| `FlowCompensated` | A compensator completed for a previously completed step. |
| `FlowPaused` | An approval gate paused a run. |

::: callout info "Audit ordering" icon:list-ordered
With persistence enabled, normal step events are dispatched only after the matching audit append succeeds.
:::
