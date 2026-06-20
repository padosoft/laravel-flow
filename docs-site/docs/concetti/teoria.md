---
title: Teoria
description: Concetti teorici dietro workflow, saga e dry-run.
---

# Teoria

Un flow e una sequenza ordinata di step. Ogni step trasforma input e contesto in un risultato. Se uno step fallisce dopo side effect precedenti, la saga compensation prova a riportare il sistema in uno stato accettabile.

$$
Run = \langle Step_1, Step_2, \dots, Step_n \rangle
$$

$$
Failure(Step_k) \Rightarrow Compensate(Step_{k-1}), \dots, Compensate(Step_1)
$$

::: callout info "Compensazione non e transazione" icon:book-open
Una transazione annulla scritture atomiche nello stesso database. Una compensazione esegue azioni business inverse dopo side effect gia visibili.
:::

## Dry-run

Il dry-run non e un secondo workflow. E lo stesso workflow eseguito con una modalita che invoca solo gli step dichiarati dry-run-aware e marca gli altri come saltati.

## Audit

L'audit e append-only durante il runtime normale. La cancellazione supportata passa dalla retention controllata di `flow:prune`.
