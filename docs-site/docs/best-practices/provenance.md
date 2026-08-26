---
title: Provenance and taint
description: Declare where a port's data comes from, and let the validator refuse a graph that carries untrusted data into a dangerous place.
---

# Provenance and taint

A flow that talks to a language model, fetches a page, or reads an inbox has
a category of bug that no amount of retry policy or compensation will help
with: **someone else chose the words**, and those words end up somewhere
that treats them as instructions.

The classic shape takes three nodes and looks entirely reasonable in a
visual editor:

```
[ ai.llm ] --text--> [ format ] --out--> [ ai.mcp.tool ] (arguments)
```

Nobody wired anything malicious. They wired a model into a formatter into a
tool call. But the tool's arguments are now attacker-choosable, and the
only thing standing between a hostile document and a real side effect is
the hope that the model ignored the instructions embedded in the text it
was summarising.

## The declaration

A port says where its authority comes from.

**Outputs** carry a `PortProvenance`:

```php
use Padosoft\LaravelFlow\Node\PortProvenance;

#[Output(type: PortType::Text, provenance: PortProvenance::Untrusted)]
public string $text;          // a model completion: someone else's words

#[Output(type: PortType::Text)]
public string $summary;       // Derived — the default

#[Output(type: PortType::Text, provenance: PortProvenance::Trusted)]
public string $action;        // a sanitization CLAIM — see below
```

**Inputs** can refuse untrusted data entirely:

```php
#[Input(type: PortType::Text, required: true, requiresTrusted: true)]
public string $command;
```

Put `requiresTrusted` where attacker-chosen bytes would become
attacker-chosen *behaviour*: a shell command, a tool name, a tool's
argument map, a URL that will be fetched with your credentials attached.
Not on every port — most ports are supposed to carry untrusted text.
Quoting a model's answer back to a user is the normal case, and marking it
a violation would only teach people to turn the check off.

## Propagation

One rule, deliberately blunt: **untrusted in, untrusted out**. If any input
to a node was untrusted, every `Derived` output of that node is untrusted
too.

There is no partial credit. A node that uppercases attacker text is handing
you attacker text in capitals. A node that parses it into an array is
handing you an array of attacker text. Passing data through a node is not
laundering it, and the analysis will not pretend otherwise.

Two things stop propagation:

- a **config literal** — the graph author typed it into the definition, so
  it is authored input, not attacker input;
- a **`Trusted` output** — see the next section.

## `Trusted` is a claim, not an escape hatch

Declaring an output `Trusted` says: *I reduced untrusted input to a value
from a set I control, and I am accountable for that.*

That is a narrow thing. It means an enum case. It means an ID that exists
in your database. It means one of five allow-listed hostnames. It means the
output cannot be attacker-chosen **no matter what came in**.

```php
// ✅ A real sanitizer: the output is one of two strings, whatever arrives.
#[Output(type: PortType::Text, provenance: PortProvenance::Trusted)]
public string $decision;

$decision = $candidate === 'archive' ? 'archive' : 'ignore';
```

```php
// ❌ NOT a sanitizer, even though it "cleans" the input.
$clean = strip_tags($modelOutput);   // still attacker-chosen bytes
$clean = addslashes($modelOutput);   // still attacker-chosen bytes
$clean = substr($modelOutput, 0, 64); // still attacker-chosen bytes
```

Escaping changes the shape of attacker data. Sanitizing replaces it with
your own. Only the second earns `Trusted`.

Note also that a sanitizing node's *other* ports stay `Derived`. A node can
emit one trusted decision and one untrusted echo of what it was given, and
wiring the wrong one is exactly the mistake this analysis exists to catch.

## Enforcement is at publish time

`GraphValidator` runs the analysis and refuses the graph:

```
Input [command] on node [shell] requires trusted data but receives
untrusted data originating at [llm.text]
(path: llm.text -> format.in -> format.out -> shell.command).
```

The message names the **origin and the whole route**, not just the sink,
because the fix is almost never at the sink — it is a sanitizer somewhere
along the path, or a wire that should not exist.

### Why publish time is enough

A flow graph's wiring is stored data. Connections are authored and
versioned; nothing rewires a published graph while it runs. So the set of
paths data can take is fully known before the graph executes, and this
analysis is **complete** for the property it checks — there is no dynamic
case for a runtime check to catch that the static pass missed.

That is an unusually strong position (taint analysis in a general-purpose
language is undecidable in the limit), and it comes from one design choice:
the graph is data, not code.

### What it does not check

The analysis reads port declarations and wires. It cannot see inside a
handler. A node that quietly calls an HTTP API and returns the body on a
port declared `Derived` has lied to the analysis, and no graph-level
reasoning would catch it.

That gap is the reason `Trusted` is written as an accountable claim rather
than inferred from what a handler appears to do. The analysis is only as
honest as the declarations, and declarations are code review's job.

## Seeing the map

The validator tells you when you are wrong. `flow:taint` tells you what is
true:

```bash
php artisan flow:taint order-triage
php artisan flow:taint order-triage --version=7 --json
```

```
Untrusted ports in [order-triage] v7:
  llm.text        <- llm.text
  format.out      <- llm.text -> format.in -> format.out
```

A graph can be perfectly valid and still be one where most nodes carry
attacker-choosable text. That is not a violation — but *"how much of this
flow is downstream of a model?"* is a question worth being able to answer,
and before this there was no way to ask it.

The command exits non-zero when the definition has violations, so it also
works as a CI gate over definitions that were stored before the analysis
existed.

## Adopting it in an existing app

Nothing changes until you declare something. Every existing port defaults
to `Derived` with `requiresTrusted: false`, which is exactly the behaviour
of a graph with no provenance model at all.

A reasonable order:

1. Mark your **sources** — the nodes that return words you did not write.
   Run `flow:taint` on your real definitions and read the map. This step
   alone is informative and cannot break anything.
2. Mark your **sinks** — `requiresTrusted` on the ports where untrusted
   data would become behaviour. Expect this to reject some graphs. That
   rejection is the feature.
3. Add **sanitizers** where the rejections are legitimate work, not where
   they are inconvenient.

If step 3 tempts you to declare `Trusted` on a port that just reformats,
the honest move is to remove the wire instead.

## The same rule, one and two layers out

This package fixes what a **graph** may connect, at publish time. Two
sibling packages answer the same question where a static graph cannot
reach:

- **`laravel-ai-guardrails`** —
  [Control P](https://doc.laravel-ai-guardrails.padosoft.com/controls/provenance-gate)
  refuses a tool call the model decided on *while reading* externally-authored
  grounding. Retrieval is dynamic, so no static analysis could have caught it;
  it needs a fact known at call time.
- **`laravel-iam-server`** —
  [AI grounding provenance](https://doc.laravel-iam-server.padosoft.com/guides/ai-grounding-provenance):
  a permission can require that the decision was not made on the strength of a
  stranger's text, with a citable decision id either way.

Three layers, increasing distance from the action, and none replaces the
others: this one stops the wire from existing, Control P stops the call, the
PDP stops the permission.

## See also

- [Security](/best-practices/security) — redaction, tokens, webhook signing
- [Forensic bundles](/operations/forensics) — proving what a run actually did
- [CLI](/reference/cli) — `flow:taint` options
