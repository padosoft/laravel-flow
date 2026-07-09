---
title: Modello dati e contratto
description: Contratti dati e persistenza di laravel-flow.
---

# Modello dati e contratto

Il contratto runtime ruota intorno a definizioni, run, step result, eventi e repository opzionali.

::: grids
::: grid
::: card "flow_runs" icon:database
Identita run, status, input, output, business impact, chiavi di correlazione e idempotenza.
:::
:::
::: grid
::: card "flow_run_nodes" icon:list-checks
Stato e payload per ogni step/nodo persistito (uno step v1 e' un nodo `legacy.step`).
:::
:::
::: grid
::: card "flow_audit" icon:scroll-text
Transizioni append-only quando audit e persistence sono attivi.
:::
:::
::: grid
::: card "flow_approvals" icon:badge-check
Token hash, stato decisionale e payload redatto.
:::
:::
:::

## Contratto pubblico

Le classi marcate `@api` sono coperte da SemVer e pinning contract-test. Le classi in namespace interni come `Persistence`, `Models`, `Queue`, `Jobs` e `Console` possono cambiare tra minor release.

::: callout warning "Payload redaction" icon:shield-alert
I DTO dashboard restituiscono cio che e memorizzato. Se disabiliti la redazione persistente, devi aggiungere una redazione applicativa prima di mostrare dati agli operatori.
:::
