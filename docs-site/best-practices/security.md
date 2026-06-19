---
title: Security
description: Security practices for laravel-flow.
---

# Security

Security-sensitive deployments should focus on payload redaction, approval token handling, webhook signing, dashboard authorization, and retention.

::: grids
::: grid
::: card "Redaction" icon:eraser
Keep redaction enabled for persisted JSON payloads and extend secret key patterns for your domain.
:::
:::
::: grid
::: card "Approvals" icon:badge-check
Approval tokens are stored as SHA-256 hashes; plain tokens are returned only at issuance.
:::
:::
::: grid
::: card "Webhooks" icon:webhook
Set `webhook.secret` so receivers can verify HMAC signatures.
:::
:::
::: grid
::: card "Dashboard" icon:shield
Replace the deny-by-default authorizer with app RBAC before exposing actions.
:::
:::
:::

::: callout warning "Displayed data" icon:eye-off
Dashboard DTOs return stored values. If stored values are not redacted, presentation code must redact them before display.
:::
