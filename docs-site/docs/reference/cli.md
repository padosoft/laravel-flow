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
| `flow:forensics` | Export one run as a content-addressed evidence bundle, or `--verify` one someone hands you. Never executes a handler. |
| `flow:taint` | Show which ports of a stored definition carry untrusted data and along which path; exits non-zero on taint violations, so it works as a CI gate. |

::: callout info "Command availability" icon:terminal
Commands are registered by the package service provider. Persistence-related commands need published migrations and a configured database when they operate on stored rows.
:::

## `flow:forensics`

```bash
php artisan flow:forensics {runId} [--output=path] [--raw] [--verify=path]
```

`--raw` skips the export-time redaction pass; routing can only be verified
on a raw bundle, so a redacted export honestly reports that check as
`unverifiable` rather than as a pass. See
[Forensic bundles](/operations/forensics).

## `flow:taint`

```bash
php artisan flow:taint {name} [--version=N] [--json]
```

Defaults to the latest stored version. Exits `1` when the definition has
taint violations, `0` otherwise — so it can gate CI on definitions stored
before the analysis existed. See
[Provenance and taint](/best-practices/provenance).
