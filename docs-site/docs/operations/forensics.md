---
title: "Forensic bundles"
description: "Export a run as content-addressed evidence, and verify it: the file has not been edited, the graph in it is the graph that ran, and the recorded inputs follow from the recorded outputs."
---

# Forensic bundles

Something went wrong. A run placed a duplicate order, approved something it
should not have, or produced an answer nobody can explain. The question is
always the same, and it is asked weeks later:

> What did this run actually see — and can I show that to someone who does not
> trust your database?

A dashboard answers the first half while the rows are still there. It does not
answer the second half at all, to an auditor, a regulator, or a customer's
lawyer.

```bash
php artisan flow:forensics 9f2c… --output=run-9f2c.json
php artisan flow:forensics --verify=run-9f2c.json
```

## What the bundle is

One **self-contained**, **content-addressed** JSON document:

- the **run** — status, subject, correlation id, input and output, timings,
  node counts;
- the **definition** — name, version, recorded checksum, and the **graph
  snapshot the run itself stored**;
- every **node in execution order** — inputs, outputs, business impact, status,
  attempts, errors, duration, cache hit.

Plus a `contentDigest` over its own canonical form, so an edited copy stops
matching itself.

The digest deliberately **excludes** the export timestamp: two exports of the
same finished run must compare equal, or the digest is useless as an identity.

## What it is not

It does not re-run the model, and it is not a claim that the recorded outputs
are *correct*. It is a claim about what was **recorded**, whether that record is
**internally consistent**, and whether it has been **altered since export**.

That distinction is the whole design. The non-deterministic half of a flow is
the node bodies — an LLM call, an HTTP request, a tool. Their outputs are
recorded because they cannot be reproduced. The **deterministic** half is the
routing between nodes, and that is the half the verifier replays.

## What `--verify` checks

Four questions, in the order an investigator asks them:

| check | catches |
|---|---|
| `content_digest` | the file was edited after export |
| `definition_checksum` | the graph in the bundle is not the graph that ran |
| `node_sequence` | duplicate or out-of-order sequences — the record was assembled, not observed |
| `input_routing` | the recorded inputs do not follow from the recorded outputs |

The last one is the deterministic replay. Re-run the router over the recorded
upstream outputs; the inputs it derives must be the inputs that were recorded.
When they are not, either the graph is not the one that ran or the node rows
were edited — and the finding **names the node where the two stories first stop
agreeing**.

The definition checksum is compared against **the snapshot the run stored**, not
against whatever is registered under that name today. Anyone with database
access could have changed the latter since.

The verifier **never executes a node handler**. It reads; it does not act. That
is what makes it safe to point at a production incident.

## Three states, not two

```
  [OK]           content_digest — The bundle matches the digest it carries.
  [OK]           definition_checksum — The embedded graph matches the checksum the run recorded.
  [UNVERIFIABLE] input_routing — The bundle is redacted: derived inputs cannot be compared against masked values.
Bundle intact: 2 check(s) passed, 1 could not be checked.
```

`unverifiable` is not a pass and not a failure. It is the verifier saying *"I
could not check this"* — a node type this installation no longer registers, a
redacted payload, an upstream output that was never recorded. Folding it into
`ok` would let a bundle that proves almost nothing come back clean, which is the
failure mode a forensic tool can least afford. `intact` means **nothing failed**;
the unverifiable count is printed next to it so nobody reads one as the other.

## Redaction

The export re-applies the bound `PayloadRedactor` **by default**. The rows were
already written under whatever policy the host chose; this second pass exists
because an export *leaves the system* — it goes into a ticket, an email, a
regulator's inbox — and the bar for a document that travels is not the bar for a
row in your own database.

The trade is stated in the output: a redacted bundle **cannot** have its routing
verified, because redaction rewrites the values the comparison would use. Use
`--raw` inside a controlled environment when routing has to be proven, and note
that the bundle records which of the two it is — so nobody has to guess later
whether a missing value was masked or never there.

## Not `flow:replay`

| | |
|---|---|
| `flow:replay {run}` | **re-executes** the run as a new, linked run — an operational tool: *do it again* |
| `flow:forensics {run}` | **executes nothing** — *show me what happened, and prove the record was not edited* |

## In CI, or in an incident

```bash
# attach evidence to the incident ticket
php artisan flow:forensics "$RUN_ID" --output="evidence/$RUN_ID.json"

# months later, before anyone argues about it
php artisan flow:forensics --verify="evidence/$RUN_ID.json"   # exit 1 if not intact
```
