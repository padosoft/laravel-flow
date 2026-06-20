---
title: Dashboard Contracts
description: Headless dashboard read-model contracts.
---

# Dashboard Contracts

The package is headless. It ships contracts and DTOs that a separate dashboard application can consume.

::: grids
::: grid
::: card "Read model" icon:table
`FlowDashboardReadModel` lists runs, approvals, webhook outbox rows, and KPIs.
:::
:::
::: grid
::: card "Authorization" icon:lock
`DashboardActionAuthorizer` is deny-by-default through `DenyAllAuthorizer`.
:::
:::
::: grid
::: card "DTOs" icon:package
Immutable DTOs carry run detail, summaries, pagination, approval summaries, audit entries, and outbox rows.
:::
:::
:::

::: callout warning "Production authorization" icon:shield
Bind your own `DashboardActionAuthorizer` before exposing dashboard actions to operators. `AllowAllAuthorizer` is for explicit development use only.
:::
