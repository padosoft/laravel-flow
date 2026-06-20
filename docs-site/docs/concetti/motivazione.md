---
title: Motivazione
description: Perche laravel-flow esiste.
---

# Motivazione

Le applicazioni Laravel spesso coordinano operazioni business composte: validazioni, simulazioni, scritture, chiamate a servizi esterni, approvazioni manuali e audit. Senza un livello esplicito, queste operazioni finiscono disperse tra controller, job, service class e listener.

laravel-flow rende il processo leggibile:

::: grids
::: grid
::: card "DX" icon:sparkles
Un junior developer deve capire la sequenza in pochi secondi.
:::
:::
::: grid
::: card "Sicurezza" icon:shield
Compensazione, redazione e audit sono progettati nel flusso.
:::
:::
::: grid
::: card "Operativita" icon:activity
Run, step, audit, approvazioni e webhook diventano osservabili quando serve.
:::
:::
:::

## Problema

`Bus::chain()` ordina job, `DB::transaction()` protegge scritture atomiche, e Symfony Workflow modella stati. Nessuno di questi offre da solo dry-run nativo, saga compensation in ordine inverso, e una singola API fluent per il dominio Laravel.

## Scelta

laravel-flow rimane dentro l'app Laravel. Questa scelta riduce il costo operativo, ma non sostituisce un workflow runtime gestito quando servono esecuzioni cross-language o multi-region.
