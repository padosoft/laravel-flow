---
title: CLI
description: Artisan command reference.
---

# CLI

| Command | Purpose |
| --- | --- |
| `flow:approve` | Approve a pending approval token from the console. |
| `flow:reject` | Reject a pending approval token from the console. |
| `flow:deliver-webhooks` | Deliver pending signed webhook outbox rows. |
| `flow:prune` | Delete retained terminal telemetry through the supported pruning path. |
| `flow:replay` | Create a new run from terminal persisted input. |

::: callout info "Command availability" icon:terminal
Commands are registered by the package service provider. Persistence-related commands need published migrations and a configured database when they operate on stored rows.
:::
