# Macro C — Graph Executor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. This is the **hardest-correctness macro** in the program: race tests and zero-write assertions are first-class deliverables, not afterthoughts. Read `docs/LESSON.md` end-to-end before writing any code — several 2026-07-07/08 entries (ModelsGenerator Flow-v2 gold patterns, plan-code-is-not-gospel, checksum list-sorting, Windows/Copilot CLI quirks) are load-bearing here.

**Goal:** A second, event-driven, parallel execution path for `padosoft/laravel-flow` that runs a persisted `GraphDefinition` (from Macro B) as a DAG — coordinator + per-node jobs, per-node retry/timeout, the `blocked`/`invalid_input`/`partially_succeeded`/`dead_letter` states, suspend/join sub-flows & fan-out, an explicit fan-in merge primitive, content-hash node cache, DAG dry-run with a cost plan, and graph-level saga compensation. This path executes **alongside** the v1 synchronous `FlowEngine`, not as a replacement: the v1 linear engine, its persistence, and its `@api` surface stay byte-for-byte untouched.

**Architecture:** New `Padosoft\LaravelFlow\Executor\` namespace (Illuminate-permitted — this layer is queue/persistence-bound, unlike `Node\`/`Graph\` which stay framework-free). The executor consumes the Macro B artifacts as read-only inputs: it resolves node types through the existing `NodeRegistry`, validates inputs through the Macro A `NodeInputValidator`/`NodeInputHydrator`, executes `FlowNodeHandler::execute(NodeContext): NodeResult`, and reads graph structure/topological order from `GraphDefinition`. Durable per-node run state lives in a **new** `flow_node_runs` table (see the big design decision in Grounding notes / open point #1 — we deliberately do NOT overload the v1 `flow_steps` table); the coordinator advances readiness inside a `lockForUpdate` transaction (the ModelsGenerator gold pattern), and every terminal node re-dispatches the coordinator (no blocked workers). Sub-flow/fan-out suspension uses a `flow_node_children` join ledger; the content-hash cache uses `flow_node_cache`. Approval gates reuse `ApprovalTokenManager` unchanged.

**Tech Stack:** PHP ^8.3, Laravel 13, PHPUnit 11/12 (suites Unit/Architecture/Contract), Orchestra Testbench 11 (`tests/Unit/Persistence/PersistenceTestCase.php` gives the sqlite `:memory:` convention), `Illuminate\Support\Facades\Queue`/`Bus` fakes + `Illuminate\Support\Sleep`/`Carbon::setTestNow` for fake-clock retry tests, PHPStan 2, Pint.

## Global Constraints

- Style learned in Macros A/B (MANDATORY): `declare(strict_types=1);`, `final` classes, readonly promoted props, snake_case test methods, parens-less `new Foo`, **never** copy `// src/...` path-comment header lines from this plan's code fences into files, run filtered tests with EXPLICIT FILE PATHS (`vendor/bin/phpunit tests/Unit/Executor/FooTest.php`) never `--filter 'A|B'` (the PowerShell `.bat` wrapper mangles regex alternation on this machine), fix Pint failures with `vendor/bin/pint <files>` then re-run.
- Local runtime **Herd PHP 8.5** via PowerShell (`%USERPROFILE%\.config\herd\bin\php85.bat`, `composer.bat`); `composer`/`php` are NOT on the Git Bash PATH. Gate after every task: `composer quality` (iterate to green; after 3 failed fix attempts → superpowers:systematic-debugging before attempt 4).
- `src/Node/` and `src/Graph/` stay Illuminate-free (Architecture sweep enforces it). The NEW `src/Executor/` MAY use Illuminate (queue, cache, DB, events) — it is infrastructure, mirroring `src/Persistence/`, `src/Queue/`, `src/Jobs/`. Extend the Architecture suite so `src/Executor/` core state machines and value objects that CAN stay pure DO stay pure (state enums, `NodeState`/`RunState`, plan/estimate VOs), while jobs/coordinator/repositories may bind Illuminate.
- **Incremental `@api` pinning in the same PR** that introduces `@api` classes: extend `tests/Contract/GraphApiContractTest.php` (or a new `tests/Contract/ExecutorApiContractTest.php` — decide in C-PR1 and keep it consistent) in EVERY PR that adds an `@api` class. `NodeAnnotationSweepTest` is path-tolerant and already sweeps `src/Node` + `src/Graph`; **extend its root list to include `src/Executor`** in C-PR1 so no executor class ships unannotated.
- **v1 is sacrosanct.** The ENTIRE pre-existing v1 Unit/Architecture/Contract suite must pass unmodified at every task boundary. Do not touch `src/FlowEngine.php`'s execute path, `flow_steps`, or any v1 `@api` class except by strictly-additive, separately-annotated new members. The graph executor is reached through a NEW entry point (`Flow::runGraph(...)` / `GraphRunner`), never by changing `Flow::run()`/`Flow::dispatch()` semantics.
- Every PR boundary (G1 → G1.5 → G2, in order): (1) `composer quality` green; (2) **local Copilot CLI review loop** per `.claude/skills/local-copilot-review/SKILL.md` — prompt MUST open with "STRICTLY READ-ONLY ANALYSIS: you MUST NOT modify, create, or delete ANY file", use the **verdict-file pattern** (`rm -f` a temp file outside the repo, instruct the CLI to WRITE findings to it, `cat` after — stdout truncation with `-s` is chronic), `git status --short` MUST be clean after every run (revert any CLI edit with `git checkout -- <file>`), iterate to NO_FINDINGS (clean narration across two runs counts if the verdict token is dropped — known CLI quirk); (3) push; (4) PR to the macro branch with Copilot reviewer via the GraphQL `requestReviewsByLogin` fallback (`copilot-pull-request-reviewer[bot]`); (5) wait CI green (PHP 8.3/8.4/8.5) + review, fix/reply/resolve threads, merge.
- Commits end with a blank line + `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push mid-task; push only at PR boundaries.
- Steps marked **VERIFY IN CODE FIRST** mean: read the named file/symbol on the current branch before writing that code; if reality differs from this plan, follow reality and note the delta in your report. Per the LESSON "plan-code is not gospel" entry, reviewers are dispatched with the named risks below aimed at THIS brief.

## Branch & PR map

Macro branch: `task/v2c-graph-executor` (from `main`, after Macro B merges). Subtask branches off it, PRs target it:

| PR | Branch | Deliverable (master-plan row) | Deferred items closed here |
|---|---|---|---|
| C-PR1 | `task/v2c-01-state-machines` | `NodeState`/`RunState` state machines + transition guards; `flow_node_runs` + `flow_runs` graph columns migration; Executor annotation sweep + Contract pin scaffold | — |
| C-PR2 | `task/v2c-02-readiness-routing` | Readiness resolver (Kahn waves) + input routing (connection mapping, coalescing) + blocked propagation + **explicit `MergeNode` fan-in primitive** + narrow `GraphValidator` fan-in carve-out | **Fan-in merge nodes** (new primitive, NOT a blanket validator relaxation) |
| C-PR3 | `task/v2c-03-sync-executor` | Sync in-memory graph executor + **legacy-node resolution strategy** + `GraphRunner` entry point + **version-exact replay re-execution** (ReplayFlowRunCommand rewired to load the pinned `StoredDefinition` graph) | **Legacy-node execution/resolution** (from Macro A); **version-exact/graph-exact replay re-execution** (from B-PR7) |
| C-PR4 | `task/v2c-04-retry-timeout` | `#[Retry]` attribute + graph-level override (tries/backoff/timeout), `dead_letter` state | — |
| C-PR5 | `task/v2c-05-queue-coordinator` | Queue coordinator + per-node jobs, `lockForUpdate` advance, idempotent dispatch (race sims) | version-exact replay on the queue path (extends C-PR3) |
| C-PR6 | `task/v2c-06-subflow-fanout` | `flow_node_children` migration; `SubFlowNode`, `ForEachNode`/`MapNode` (maxConcurrency); locked join | — |
| C-PR7 | `task/v2c-07-node-cache` | `#[Cacheable]` + `flow_node_cache` migration + canonical content hash | — |
| C-PR8 | `task/v2c-08-graph-saga` | Graph saga: reverse-topological compensation, parallel strategy, aggregate compensator (`withAggregateCompensator`) | — |
| C-PR9 | `task/v2c-09-dag-dry-run` | DAG dry-run: execution plan (waves) + cost estimate from node cost hints | — |
| C-PR10 | `task/v2c-10-approval-gate-node` | `ApprovalGateNode` on graphs (reuses `ApprovalTokenManager`); pause/resume across coordinator | — |

**Setup:**

```bash
git checkout main && git pull
git checkout -b task/v2c-graph-executor && git push -u origin task/v2c-graph-executor
git checkout -b task/v2c-01-state-machines
```

---

<!-- GROUNDING NOTES AND PER-TASK SECTIONS APPENDED BELOW ONCE CODE-READ EXPLORERS RETURN -->
