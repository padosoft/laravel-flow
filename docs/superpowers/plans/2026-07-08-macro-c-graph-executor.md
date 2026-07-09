# Macro C — Graph Executor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. These are global Claude Code skills provided by the `superpowers` plugin at the session/runtime level (not repo-local — they are not and should not be duplicated under this repo's `.claude/skills/`, same as Macro A's and Macro B's plans that also reference them). If your environment doesn't have that plugin available, fall back to this repo's own local gates and review-loop skills (`.claude/skills/local-copilot-review/SKILL.md`, `.claude/skills/copilot-pr-review-loop/SKILL.md`) plus a plain task-by-task TDD discipline — the checkbox (`- [ ]`) structure below works standalone either way. Steps use checkbox (`- [ ]`) syntax for tracking. This is the **hardest-correctness macro** in the program: race tests and zero-write assertions are first-class deliverables, not afterthoughts. Read `docs/LESSON.md` end-to-end before writing any code — several 2026-07-07/08 entries are load-bearing here (ModelsGenerator Flow-v2 gold patterns: event-driven coordinator with `lockForUpdate` + Kahn readiness, suspend/join `flow_node_children` ledger, content-hash node cache, per-run broadcast channel; bug classes to avoid: untyped node inputs, hand-duplicated palette whitelists, `tries=1` with app-level uniform retry, missing `blocked` state; plus "plan-code is not gospel", checksum list-sorting, `--version` reserved-option, Windows/Copilot CLI quirks).

**Goal:** A production-grade event-driven graph runtime for `padosoft/laravel-flow`, plus the persistence unification that makes it the *canonical* execution model rather than a bolt-on. Deliverables: coordinator + per-node jobs, per-node retry/timeout, the new `blocked`/`invalid_input`/`partially_succeeded`/`dead_letter` states, suspend/join sub-flows & fan-out, an explicit fan-in merge primitive, a content-hash node cache, DAG dry-run with a cost plan, graph-level saga compensation — AND a single unified per-node persistence model (`flow_run_nodes`) written by BOTH the v1 linear engine and the new graph executor, retiring the separate `flow_steps` table.

**Design freedom (user decision, 2026-07-09):** `padosoft/laravel-flow` has exactly one downstream consumer, controlled by the same team on its own upgrade schedule. v2 is a major release. Therefore the v1 *public fluent API* and `@api` classes stay backward-compatible (any `@api` change follows the normal major process — mirrored in `tests/Contract` + `docs/UPGRADE.md`), but v1's *internal persistence schema* is free to be redesigned and migrated. We take the spec's "`flow_steps` becomes per-node rows" to its full, coherent conclusion: one table, one repository, both engines.

**Architecture:** New `Padosoft\LaravelFlow\Executor\` namespace (Illuminate-permitted — this layer is queue/persistence/event-bound, unlike `Node\`/`Graph\` which stay framework-free). The executor consumes Macro A/B artifacts as read-only inputs: it resolves node types through the existing `NodeRegistry`, validates inputs through the Macro A `NodeInputValidator`/`NodeInputHydrator`, executes `FlowNodeHandler::execute(NodeContext): NodeResult`, and reads structure/topological order from `GraphDefinition`. Durable per-node state lives in the unified `flow_run_nodes` table behind a single `RunNodeRepository` (`@api`) used by both engines: for the v1 linear engine a step is a node (`node_id = step name`, `node_type = 'legacy.step'`, `sequence` preserved); for the graph executor a node is a node. The coordinator advances readiness inside a `lockForUpdate` transaction (ModelsGenerator gold pattern), and every terminal node re-dispatches the coordinator (no blocked workers). Sub-flow/fan-out suspension uses a `flow_node_children` join ledger; the content-hash cache uses `flow_node_cache`. Approval gates reuse `ApprovalTokenManager` unchanged.

**Scope boundary (deliberate):** this macro unifies PERSISTENCE, not the execution engines. The v1 fluent path keeps its linear synchronous engine and its exact public behavior (compensation ordering, approval resume machinery, sync semantics); it merely records transitions through the unified repository. Fully routing v1 fluent execution *through the graph executor* is the natural next evolution but is explicitly OUT of Macro C scope — the unified persistence model sets it up cleanly for a future macro without concentrating that behavioral risk in the hardest-correctness macro.

**Tech Stack:** PHP ^8.3, Laravel 13, PHPUnit 11/12 (suites Unit/Architecture/Contract), Orchestra Testbench 11 (`tests/Unit/Persistence/PersistenceTestCase.php` gives the sqlite `:memory:` convention), `Illuminate\Support\Facades\Queue`/`Bus` fakes + `Carbon::setTestNow()` / `Illuminate\Support\Sleep::fake()` for fake-clock retry tests, PHPStan 2, Pint.

---

## Grounding notes (facts verified against the current codebase before writing this plan)

Every claim below was read from source on branch `task/v2b-07-run-version-pinning` (Macro B head). Follow reality over this plan if it has drifted by the time you implement; note deltas in your report.

**Node contract (Macro A — `src/Node/`):**
- `interface FlowNodeHandler` — the only method is `execute(NodeContext $context): NodeResult`. (No `compensate()` on the interface today — graph-node compensation is designed in C-PR8 as a separate `CompensatableNode` capability; VERIFY before assuming.)
- `final class NodeContext` (`@api`, readonly): ctor `(string $flowRunId, string $definitionName, string $nodeId, array $inputs, bool $dryRun = false)`. `$inputs` keyed by input port key, already validated/hydrated.
- `final class NodeResult` (`@api`, private ctor): props `bool $success, array $outputs, ?Throwable $error, ?array $businessImpact, bool $dryRunSkipped, bool $paused`. Statics: `success(array $outputs = [], ?array $businessImpact = null)`, `failed(Throwable $error)`, `dryRunSkipped()`, `paused(array $outputs = [], ?array $businessImpact = null)`. `$outputs` keyed by output port key.
- `final class NodeDefinition` (`@api`, readonly): ctor `(string $type, string $name, string $category, ?string $icon, ?string $description, list<PortDefinition> $inputs, list<PortDefinition> $outputs, string $handlerClass)`. Methods `input(string $key): ?PortDefinition`, `output(string $key): ?PortDefinition`, `toArray(): array`.
- `PortType` (`@api` string enum): cases `Text, Int, Float, Bool, Json, Any`. `accepts(PortType $source): bool`, `validates(mixed $value): bool`.
- `PortDefinition` (`@api`, readonly): `key`, `type` (`PortType`), `required=false`, `label=null`, `propertyName=null`; `toArray()`. (**No `multiple`/variadic flag today — C-PR2 adds one additively.**)
- `NodeRegistry` (`@api`): `get(string $type): NodeDefinition` (throws `UnknownNodeTypeException`), `has(string $type): bool`, `all(): array`, `register(...)`, `registerMany(array $classes)`. **`register()` accepts only `FlowNodeHandler` classes — legacy steps resolve via the adapter (below).**
- `final class LegacyStepNodeAdapter implements FlowNodeHandler` (`@api`): ctor `(private readonly FlowStepHandler $step)`. `public static definitionFor(string $nodeType, string $stepHandlerClass): NodeDefinition` builds a definition with one `input` Json input port + one `output` Json output port, `category: 'legacy'`, `handlerClass: $stepHandlerClass`. `execute()` reads `$context->inputs['input']` (must be an array or `NodeResult::failed`), wraps it in a v1 `FlowContext(flowRunId, definitionName, input, stepOutputs: [], dryRun)`, catches `Throwable` → `failed` (v1 throw-to-fail preserved), maps `FlowStepResult` → `NodeResult` 1:1 (`dryRunSkipped`/`paused`/`!success`/`success` with `['output' => $result->output]`). **This is the exact blueprint for C-PR3's legacy resolution.**

**Graph model (Macro B — `src/Graph/`):**
- `final class GraphDefinition` (`@api`, readonly): ctor `(list<GraphNode> $nodes, list<Connection> $connections, array $metadata = [])`. Public readonly `$nodes`, `$connections`, `$metadata`. `topologicalOrder(): list<string>` (node ids in Kahn order, **computed once at construction**, private cache; ctor throws `InvalidGraphException` on cycle/structural violation). `node(string $id): ?GraphNode`, `nodeIds(): list<string>`.
- `final class GraphNode` (`@api`, readonly): `id`, `type`, `config` (array), `?position`.
- `final class Connection` (`@api`, readonly): `sourceNodeId`, `sourcePortKey`, `targetNodeId`, `targetPortKey`; `identity(): string`. Rejects self-loops.
- `final class GraphValidator` (`@api`): ctor `(NodeRegistry $registry)`, `validate(GraphDefinition $graph): void`. **Anti-fan-in rule confirmed** (`$wiredInputs[$targetNodeId][$targetPortKey]` → rejects a second wire into the same `(node, inputPort)`) — VERIFY the exact violation message before C-PR2 relaxes it for `multiple` ports.
- `final class GraphSerializer` (`@api`): `SCHEMA_VERSION = 1`, `KIND = 'laravel-flow'`; `toArray`/`fromArray`/`toJson`/`fromJson`/`checksum` (sha256 of recursively key-sorted array — semantically stable). `StoredDefinition::$graph` holds the `toArray()` envelope; round-trip via `fromArray()`.
- `final class StoredDefinition` (`@api`, readonly): `int $id, string $name, int $version, string $status, array $graph, string $checksum, ?string $signature, ?DateTimeImmutable $publishedAt`; consts `STATUS_DRAFT/PUBLISHED/ARCHIVED`.
- `interface DefinitionRepository` (`@api`): `createDraft`, `createDraftIfChanged(...): ?StoredDefinition`, `find(string $name, int $version): StoredDefinition`, `latest(string $name, ?string $status = null): ?StoredDefinition`, `publish`, `archive`, `versions(string $name): array`.
- `FlowDefinition::toGraphDefinition(): GraphDefinition` compiles a v1 chain to `legacy.step` nodes (config `['handler'=>fqcn, 'supports_dry_run'=>bool, 'compensator'=>fqcn|null, 'approval_gate'=>bool]`), consecutive steps wired `(prev,'output')→(next,'input')`, metadata `['required_inputs','aggregate_compensator','compiled_from'=>'v1-builder']`. Reserved type constant `FlowDefinition::LEGACY_NODE_TYPE` (VERIFY exact name) `= 'legacy.step'`; not in the `NodeRegistry`. **This equivalence — a v1 step is already modelled as a `legacy.step` node — is the conceptual basis for the unified persistence model.**

**v1 engine + persistence (`src/FlowEngine.php`, `src/Persistence/`, `database/`):**
- `FlowEngine` (`@internal`): `execute(string $name, array $input, ?FlowExecutionOptions $options = null): FlowRun`; `registerDefinition(FlowDefinition $definition): void`. **Run and step status values are bare string literals, and they are NOT the same vocabulary** (Copilot review, PR #55: an earlier draft of this note wrongly listed `'skipped'` as a run status). RUN status values come from `FlowRun::STATUS_*`: `'pending'`, `'running'`, `'paused'`, `'succeeded'`, `'failed'`, `'compensated'`, `'aborted'` (no `blocked`/`invalid_input`/`partially_succeeded`/`dead_letter` yet — those are new `RunState` cases). STEP status literals (from `persistedStepStatus()`): `'running'`, `'succeeded'`, `'failed'`, `'skipped'`, `'paused'` — `'skipped'` is a STEP-only concept (a step whose `supportsDryRun`-gated handler didn't run), never a run status. Step persistence flows through `FlowStore` → `StepRunRepository` (VERIFY the exact `persistStepStarted`/`persistStepFinished` → `steps()->createOrUpdate()`/`upsert()` seam — this is the code that moves onto `flow_run_nodes` in Task 3).
- **Pinning bookkeeping (B-PR7):** private `array $definitionVersionPins = []` keyed by definition name; set in `registerDefinition()` (≈line 185), `unset()` on the unpinned path (≈lines 148/198); at run creation writes `flow_runs.definition_version` + `definition_checksum`. Graph runs maintain the analogous pin from their `StoredDefinition`.
- `flow_runs` (migration `2026_05_02_000001`): `id` string(36) PK, `definition_name` idx, `status` string(32) idx, `dry_run` bool, `input`/`output`/`business_impact` json, `failed_step`, `compensated` bool, `compensation_status` string(32) idx, `correlation_id` idx, `idempotency_key` unique, `started_at`/`finished_at` tz, `duration_ms`, timestamps, index `[finished_at, id]`. B-PR7 added nullable `definition_version` + `definition_checksum` string(64) (`...000006`).
- `flow_steps` (v1): `id`, `run_id` string(36), `sequence`, `step_name`, `handler`, `status` string(32) idx, json `input`/`output`/`business_impact`, `error_class`/`error_message`, `dry_run_skipped`, timings, **unique `[run_id, step_name]`**, index `[run_id, status]`, FK `run_id → flow_runs cascadeOnDelete`. **This table is superseded by `flow_run_nodes` in C-PR1 (its columns map 1:1 — see Task 2).**
- `flow_audit` (migration `2026_05_02_000001`): `id`, `run_id` idx, `step_name` nullable idx, `event` idx, `payload`/`business_impact` json, `occurred_at`/`created_at`. (Audit rows key on `step_name`; when unified, `step_name` = `node_id` — VERIFY and keep the column name or rename with the data migration.)
- Migration style: `database/migrations/2026_MM_DD_00000N_*.php` returning an anonymous `Migration`; `Schema::hasTable`/`hasColumn` guards; additive `->after()` guarded by `hasColumn` (portability note in `...000006`). Every additive migration MUST be added to `LaravelFlowServiceProvider::publishesMigrations()` and the SP publish smoke test (LESSON 2026-05-04).
- `RunFlowJob` (`@internal`, `ShouldQueueAfterCommit` + `InteractsWithQueue`): the **distributed-lock idempotency blueprint** for C-PR5. Per-dispatch cache lock key `laravel-flow:run:{dispatchId}`, completion marker `…:completed`. `handle(FlowEngine, CacheFactory, ConfigRepository)`: rejects `ArrayStore` unless queue driver `sync` (`allowsProcessLocalLocks`), rejects non-`LockProvider`, checks the completion marker BEFORE and AFTER `->get()`, `release($retry)` on lock-miss (no throw), writes the marker in a `finally` releasing the lock only when not completed, `fail()`s if the marker write fails. Config: `queue.lock_store`, `queue.lock_seconds` (3600), `queue.lock_retry_seconds` (30), `queue.tries`, `queue.backoff_seconds`. `QueueRetryPolicy::normalizeTries()/normalizeBackoffSeconds()` parse integer strings only (`0`=unlimited, `null`=defer).
- `Facade\Flow` proxies: `define`, `execute`, `dryRun`, `dispatch`, `resume`, `reject`, `definitions`, `definition`, `registerDefinition`. **Graph entry points are NEW methods (`runGraph`, `dispatchGraph`, `dryRunGraph`, `replayGraph`) — never change existing v1 method semantics.**
- `config/laravel-flow.php` top-level keys: `default_storage`, `persistence` (`enabled`, `redaction`, `retention`), `queue`, `approval`, `audit_trail_enabled`, `dry_run_default`, `webhook`, `step_timeout_seconds`, `compensation_strategy`, `compensation_parallel_driver`, `nodes` (`handlers`,`discovery`), `definitions` (`signing_secret`,`persist_registered`). **No `broadcasting`/`executor` key yet** — C-PR1 adds an `executor` block; `broadcasting` is Macro D.
- `Dashboard\FlowDashboardReadModel` + read DTOs (`RunSummary`, `StepSummary`, `RunDetail`, …) are `@api` and read `flow_steps` today. Under unification they read `flow_run_nodes`; the DTO SHAPES stay identical (a "step" projection is a run-node projection) so the Dashboard public contract is preserved.
- `ApprovalTokenManager`/`ApprovalGate` reused unchanged in C-PR10; VERIFY issue/consume signatures (returns `IssuedApprovalToken`, hash-only storage).

---

## Persistence design decision (RESOLVED — no human sign-off pending)

**Chosen: one unified `flow_run_nodes` table + one `RunNodeRepository` (`@api`), written by both engines; `flow_steps` retired with a data migration.** (Team-lead options 1/2/3 → **option 1, executed properly**.)

**Why option 1 over a graph-only table (option 2):** Macro B already models a v1 step as a `legacy.step` graph node (`FlowDefinition::toGraphDefinition()`), so v1 execution is conceptually a degenerate graph run. A single per-node table is therefore the *natural* data model, not a forced fit. It pays off across the whole program: **Macro D** emits ONE progress-snapshot shape for every run; **Macro E**'s console renders ONE row type; **Macro G**'s upgrade story is "steps became nodes" (a clean migration) instead of "we ship two step-shaped tables forever." Option 2 (a permanent second table) is the low-ambition half-measure the relaxed constraint explicitly frees us from. A more radical option 3 (route v1 fluent EXECUTION through the graph executor too) is superior long-term but concentrates unacceptable behavioral risk in this macro — deferred as a future macro; the unified persistence is the correct, bounded step now.

**Public-surface impact (all permitted for a major, all done via the normal `@api` process):** `Contracts\StepRunRepository` (`@api`) is superseded by `Contracts\RunNodeRepository` (`@api`); `FlowStore` (`@api`) is updated to aggregate the node-run repository; the `flow_steps` table/Model (`@internal`) is retired. `Dashboard\StepSummary` and the read-model method signatures are UNCHANGED (projection preserved). Every contract change is mirrored in `tests/Contract` + `docs/UPGRADE.md` in the PR that makes it, with a documented data-migration path for custom-store implementers.

**Where the deferred items land (locked):** legacy-node execution/resolution → **C-PR3**; version-exact/graph-exact replay re-execution → **C-PR3** (queue path extends in C-PR5); fan-in merge → **C-PR2** (explicit `MergeNode` + a narrow per-port `GraphValidator` carve-out via a new `multiple` port flag).

---

## Global Constraints

- Style learned in Macros A/B (MANDATORY): `declare(strict_types=1);`, `final` classes, readonly promoted props, snake_case test methods, parens-less `new Foo`, **never** copy `// src/...` path-comment header lines from this plan's code fences into files, run filtered tests with EXPLICIT FILE PATHS (`vendor/bin/phpunit tests/Unit/Executor/FooTest.php`) never `--filter 'A|B'` (the PowerShell `.bat` wrapper mangles regex alternation), fix Pint failures with `vendor/bin/pint <files>` then re-run.
- Local runtime **Herd PHP 8.5** via PowerShell (`%USERPROFILE%\.config\herd\bin\php85.bat`, `composer.bat`); `composer`/`php` are NOT on the Git Bash PATH. Gate after every task: `composer quality` (iterate to green; after 3 failed fix attempts → superpowers:systematic-debugging before attempt 4).
- `src/Node/` and `src/Graph/` stay Illuminate-free (Architecture sweep enforces it). The NEW `src/Executor/` MAY use Illuminate. **Keep the pure pieces pure:** state enums (`NodeState`/`RunState`), transition guards, the readiness resolver, input router, dry-run plan/estimate VOs, and the content-hash function take no framework dependency and get plain-`TestCase` unit tests; jobs/coordinator/repositories/control-nodes may bind Illuminate and use `PersistenceTestCase`.
- **Incremental `@api` pinning in the same PR** that introduces `@api` classes: create `tests/Contract/ExecutorApiContractTest.php` in C-PR1 and EXTEND it every later PR. `NodeAnnotationSweepTest` is path-tolerant — **extend its root list to include `src/Executor` in C-PR1**. Any change to a Macro A/B/v1 `@api` class (the `#[Input]`/`PortDefinition` `multiple` flag in C-PR2; the `RunNodeRepository`/`FlowStore` contract changes in C-PR1; `#[Retry]`/`#[Cacheable]`/`#[Cost]` reflected on `NodeDefinition`; a graph-node `compensate` capability) is mirrored in `tests/Contract` + `docs/UPGRADE.md` in the SAME PR.
- **v1 PUBLIC API stays stable; v1 INTERNAL persistence is free to evolve** (user decision 2026-07-09). The v1 fluent builder (`Flow::define()->step()->…->register()`), the engine's public execution methods (`execute`/`dryRun`/`dispatch`/`resume`/`reject`), and every `@api` class users write against remain backward-compatible (changes go through the normal major `@api` process). But v1's *internal* persistence schema/`@internal` implementation (`flow_steps` + its Models/migrations, internal persistence namespaces) MAY be redesigned/migrated/retired: exactly one downstream consumer exists and controls its own upgrade timing. v1 EXECUTION SEMANTICS (step ordering, compensation order, approval resume) must remain observably identical — only the persistence target changes. The graph executor is reached ONLY through new entry points (`Flow::runGraph(...)`, `GraphRunner`); never change existing v1 method semantics.
- Every PR boundary (G1 → G1.5 → G2, in order): (1) `composer quality` green; (2) **local Copilot CLI review loop** per `.claude/skills/local-copilot-review/SKILL.md` — prompt MUST open with "STRICTLY READ-ONLY ANALYSIS: you MUST NOT modify, create, or delete ANY file", use the **verdict-file pattern** (`rm -f` a temp file OUTSIDE the repo, instruct the read-only CLI to WRITE findings to it, `cat` after — stdout truncation with `-s` is chronic), verify `git status --short` clean after every run (revert any CLI edit with `git checkout -- <file>`), iterate to NO_FINDINGS (clean narration across two runs counts if the verdict token is dropped); (3) push; (4) PR to the macro branch, Copilot reviewer via the GraphQL `requestReviewsByLogin` fallback (`copilot-pull-request-reviewer[bot]`); (5) wait CI green (PHP 8.3/8.4/8.5) + review, fix/reply/resolve threads, merge. Record every reusable review lesson in `docs/LESSON.md`.
- Commits end with a blank line + `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Push only at PR boundaries. Reviewers are dispatched with the NAMED RISKS in each PR's "Edge cases & races" aimed at THIS brief (LESSON: plan-code is not gospel).
- Steps marked **VERIFY IN CODE FIRST** mean: read the named file/symbol on the current branch before writing that code; if reality differs, follow reality and note the delta in your report.

## Branch & PR map

Macro branch: `task/v2c-graph-executor` (from `main`, after Macro B merges). Subtask branches off it, PRs target it:

| PR | Branch | Tasks | Notes |
|---|---|---|---|
| C-PR1 | `task/v2c-01-unified-persistence` | 1, 2, 3 | state machines + unified `flow_run_nodes` schema + **v1 engine rewired onto it, `flow_steps` retired** |
| C-PR2 | `task/v2c-02-readiness-routing` | 4, 5, 6 | readiness/blocked, routing/coalescing, **MergeNode fan-in** |
| C-PR3 | `task/v2c-03-sync-executor` | 7, 8, 9 | **legacy resolution**, sync `GraphRunner`, **version-exact replay** |
| C-PR4 | `task/v2c-04-retry-timeout` | 10 | `#[Retry]` + dead-letter |
| C-PR5 | `task/v2c-05-queue-coordinator` | 11, 12 | queue coordinator + node jobs (race macro) |
| C-PR6 | `task/v2c-06-subflow-fanout` | 13, 14 | `flow_node_children`, sub-flow, fan-out, locked join |
| C-PR7 | `task/v2c-07-node-cache` | 15 | `#[Cacheable]` + `flow_node_cache` |
| C-PR8 | `task/v2c-08-graph-saga` | 16 | graph saga compensation |
| C-PR9 | `task/v2c-09-dag-dry-run` | 17 | DAG dry-run plan + cost |
| C-PR10 | `task/v2c-10-approval-gate-node` | 18 | `ApprovalGateNode` + pause/resume |

**Setup:**

```bash
git checkout main && git pull
git checkout -b task/v2c-graph-executor && git push -u origin task/v2c-graph-executor
git checkout -b task/v2c-01-unified-persistence
```

---

## C-PR1 — State machines + unified per-node persistence

### Task 1: `NodeState` / `RunState` enums + transition guards

**Objective (verifiable):** Two pure PHP enums with an explicit legal-transition table exist; every illegal transition throws `IllegalStateTransitionException`, proven by an exhaustive matrix test.

**Files:**
- Create: `src/Executor/State/NodeState.php`, `src/Executor/State/RunState.php`, `src/Executor/State/IllegalStateTransitionException.php`
- Modify: `tests/Architecture/NodeAnnotationSweepTest.php` (add `'/../../src/Executor'`); create `tests/Contract/ExecutorApiContractTest.php`
- Test: `tests/Unit/Executor/State/NodeStateTest.php`, `tests/Unit/Executor/State/RunStateTest.php`

**Interfaces:**
- `enum NodeState: string` (`@api`) cases: `Pending='pending'`, `Running='running'`, `Paused='paused'`, `Succeeded='succeeded'`, `Failed='failed'`, `Skipped='skipped'`, `Blocked='blocked'`, `InvalidInput='invalid_input'`, `DeadLetter='dead_letter'`. Methods `isTerminal(): bool`, `canTransitionTo(self $to): bool`, `transitionTo(self $to): self` (throws when illegal). **Corrected from an earlier draft** (Copilot review, PR #55): the success case is `Succeeded`, not `Completed` — v1's persisted step status is the literal string `'succeeded'`, and the plan's own claim that these enums are "the single source of truth for both engines' status strings" is only true if the value matches. Likewise `Paused` is a REAL node state (not folded into `Running`): v1 persists a paused approval-gate step's `flow_steps.status` as the literal string `'paused'`, and the unified `flow_run_nodes.status` column must keep storing that same value for a v1 step (and, for the graph executor, a node blocked on its own `ApprovalGateNode`) — collapsing it into `Running` would be an observable persisted-status regression, not just an internal simplification.
- `enum RunState: string` (`@api`) cases (**every case needs its explicit string value** — Copilot review flagged several bare cases in the earlier draft, which doesn't compile for a backed enum): `Pending='pending'`, `Running='running'`, `Paused='paused'`, `Succeeded='succeeded'`, `PartiallySucceeded='partially_succeeded'`, `Failed='failed'`, `Compensated='compensated'`, `Aborted='aborted'`, `DeadLetter='dead_letter'`. Same three methods. **These enums are the single source of truth for BOTH engines' status strings** — v1's STEP statuses (`'running'`/`'succeeded'`/`'failed'`/`'skipped'`/`'paused'`) map onto `NodeState` values of the SAME string, and v1's RUN statuses (`'pending'`/`'running'`/`'paused'`/`'succeeded'`/`'failed'`/`'compensated'`/`'aborted'`) map onto `RunState` values of the SAME string; `'skipped'` is a `NodeState`-only case (v1 never persists a `'skipped'` RUN), so unification extends each vocabulary independently without changing v1's persisted status strings.
- `IllegalStateTransitionException extends \LogicException` (`@api`) — message `"Illegal {enum} transition [{from}] → [{to}]."`.

**Legal transition tables** (encode as `match` inside `canTransitionTo`). `Paused` preserves v1's literal persisted step status for an approval gate (see the corrected case list above) — a node enters `Paused` on `NodeResult::paused()` and leaves it only via resume (`→ Running`) or reject (`→ Failed`); `Failed → Running` (retry) is the OTHER node resurrection edge.

```php
// NodeState::canTransitionTo
return match ($this) {
    self::Pending => in_array($to, [self::Running, self::Skipped, self::Blocked, self::InvalidInput], true),
    self::Running => in_array($to, [self::Paused, self::Succeeded, self::Failed, self::DeadLetter], true),
    self::Paused  => in_array($to, [self::Running, self::Failed], true), // resume / reject
    self::Failed  => in_array($to, [self::Running, self::DeadLetter], true), // retry / give up
    self::Succeeded, self::Skipped, self::Blocked, self::InvalidInput, self::DeadLetter => false, // terminal
};

// RunState::canTransitionTo
return match ($this) {
    self::Pending => $to === self::Running,
    self::Running => in_array($to, [self::Paused, self::Succeeded, self::PartiallySucceeded, self::Failed, self::Aborted, self::DeadLetter], true),
    self::Paused  => in_array($to, [self::Running, self::Failed, self::Aborted], true),
    self::Failed, self::PartiallySucceeded => $to === self::Compensated,
    self::Succeeded, self::Compensated, self::Aborted, self::DeadLetter => false, // terminal
};
```

- [ ] **Step 1: Failing tests.** `NodeStateTest`: `test_terminal_states_report_terminal`, `test_legal_transitions_are_allowed` (data provider over every legal edge, incl. `Running→Paused`, `Paused→Running`, `Paused→Failed`), `test_illegal_transitions_throw` (representative illegal set: `Succeeded→Running`, `Pending→Succeeded`, `Blocked→Running`, `DeadLetter→Running`, `Paused→Succeeded`), assert the message contains both values. Mirror `RunStateTest`.
- [ ] **Step 2: RED.** **Step 3: Implement** (Illuminate-free). **Step 4: GREEN** + extend the annotation sweep root list and pin every enum case value in `ExecutorApiContractTest` (`assertSame('blocked', NodeState::Blocked->value)`, `assertSame('paused', NodeState::Paused->value)`, `assertSame('succeeded', NodeState::Succeeded->value)` etc. — pin ALL cases of both enums, not a sample, since a future accidental value rename would otherwise silently break the "single source of truth for persisted status strings" guarantee). **Step 5:** `composer quality`; commit `feat(executor): node and run state machines with transition guards`.

**Edge cases the tests MUST cover:** `Failed → Running` retry is legal but `Succeeded → Running` is NOT (idempotency); `Paused → Running` (resume) and `Paused → Failed` (reject) are legal but `Paused → Succeeded` is NOT (a resumed node must re-enter `Running` and complete normally, never skip straight to success); `Blocked` has zero outgoing edges (poisoned-by-upstream marker, distinct from the deliberate `Skipped`); `InvalidInput` is terminal and distinct from `Failed`; every case is reachable-or-terminal (no orphan).

### Task 2: Unified `flow_run_nodes` table + graph run columns

**Objective:** A single `flow_run_nodes` table (superseding `flow_steps`) plus additive `flow_runs` columns (`engine`, progress counters) exist and publish via the SP, asserted by a migration test.

**Files:**
- Create: `database/migrations/2026_07_09_000007_create_flow_run_nodes_table.php`, `database/migrations/2026_07_09_000008_add_graph_columns_to_laravel_flow_runs.php`
- Modify: `src/LaravelFlowServiceProvider.php` (`publishesMigrations()`), `config/laravel-flow.php` (add `executor` block, below)
- Test: extend `tests/Unit/Persistence/PersistenceMigrationTest.php` (**VERIFY IN CODE FIRST**), extend the SP publish smoke test

**`flow_run_nodes` schema** (a superset of `flow_steps`, plus graph/retry/cache/state columns; `flow_steps` columns map 1:1 so the v1 rewire in Task 3 is mechanical):
```php
Schema::create('flow_run_nodes', function (Blueprint $table): void {
    $table->id();
    $table->string('run_id', 36);
    $table->unsignedInteger('sequence')->nullable();   // v1: step order; graph: topological index (display)
    $table->string('node_id');                          // v1: step_name; graph: GraphNode id
    $table->string('node_type');                        // 'legacy.step' for v1 steps + compiled legacy; real type for graph
    $table->string('handler')->nullable();              // resolved handler class (v1 parity)
    $table->string('status', 32)->index();              // NodeState->value
    $table->unsignedInteger('attempts')->default(0);
    $table->json('inputs')->nullable();                 // resolved+redacted input port map (v1: step input)
    $table->json('outputs')->nullable();                // output port map (redacted) (v1: step output)
    $table->json('business_impact')->nullable();
    $table->string('error_class')->nullable();
    $table->text('error_message')->nullable();
    $table->boolean('dry_run_skipped')->default(false);
    $table->string('cache_hit')->nullable();            // null=n/a, content hash when served from cache
    $table->timestampTz('available_at')->nullable();    // retry/backoff gate
    $table->timestampTz('started_at')->nullable();
    $table->timestampTz('finished_at')->nullable();
    $table->unsignedInteger('duration_ms')->nullable();
    $table->timestampsTz();

    $table->unique(['run_id', 'node_id']);
    $table->index(['run_id', 'status']);
    $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
});
```
**`flow_runs` additive columns** (guarded `hasColumn`, `->after()` only when the anchor exists — copy the portability comment from `...000006`): `engine` string(16) nullable (`'v1'`=linear engine, `'graph'`=executor; null tolerated as `'v1'`), `nodes_total`/`nodes_completed`/`nodes_failed` unsignedInt nullable (progress — one shape for Macro D/E).

**`executor` config block** (append; mirror the doc-comment style):
```php
'executor' => [
    'coordinator_timeout_seconds' => (int) env('LARAVEL_FLOW_EXECUTOR_COORDINATOR_TIMEOUT', 120),
    'node_timeout_seconds' => (int) env('LARAVEL_FLOW_EXECUTOR_NODE_TIMEOUT', 300),
    'lock_store' => env('LARAVEL_FLOW_EXECUTOR_LOCK_STORE', null),
    'lock_seconds' => (int) env('LARAVEL_FLOW_EXECUTOR_LOCK_SECONDS', 3600),
    'default_tries' => env('LARAVEL_FLOW_EXECUTOR_DEFAULT_TRIES', 1),
    'default_backoff_seconds' => env('LARAVEL_FLOW_EXECUTOR_DEFAULT_BACKOFF', 0),
    'queue' => env('LARAVEL_FLOW_EXECUTOR_QUEUE', null),
    'cache' => [
        'store' => env('LARAVEL_FLOW_EXECUTOR_CACHE_STORE', null),
        'ttl_seconds' => env('LARAVEL_FLOW_EXECUTOR_CACHE_TTL', null), // null = forever
    ],
],
```

- [ ] TDD: migration assertions first (RED), then migrations + SP entries + config block (GREEN). Assert `flow_run_nodes` columns/indexes/unique and the `flow_runs` additions. Commit `feat(persistence): unified flow_run_nodes table and graph run columns`.

### Task 3: Rewire the v1 engine onto `flow_run_nodes`; retire `flow_steps`

**Objective:** The v1 linear `FlowEngine` persists step transitions into `flow_run_nodes` through a single `RunNodeRepository`; `flow_steps` is retired with a data-migration path; the v1 regression suite passes with persistence assertions repointed, and v1 EXECUTION behavior is observably unchanged.

**Files:**
- Create: `src/Contracts/RunNodeRepository.php` (`@api`), `src/Persistence/EloquentRunNodeRepository.php` (`@internal`), `src/Models/FlowRunNodeRecord.php` (`@internal`), `database/migrations/2026_07_09_000009_migrate_flow_steps_to_run_nodes.php` (copy any existing `flow_steps` rows into `flow_run_nodes`, mapping `step_name→node_id`, `node_type='legacy.step'`, then `Schema::dropIfExists('flow_steps')`; guard on `hasTable`)
- Modify: `src/Contracts/FlowStore.php` (`@api`: replace the `steps()` step-repository accessor with `runNodes(): RunNodeRepository`; **VERIFY** current shape), `src/Persistence/EloquentFlowStore.php`, `src/FlowEngine.php` (repoint `persistStepStarted`/`persistStepFinished`/audit `step_name` writes to node rows — a linear step becomes a node with `sequence` preserved and `node_type='legacy.step'`), `src/Dashboard/FlowDashboardReadModel.php` (read `flow_run_nodes`; keep `StepSummary`/`RunDetail` shapes), `src/Persistence/FlowPruner.php` + `flow:prune` (delete `flow_run_nodes` child rows), remove `src/Contracts/StepRunRepository.php` + `src/Persistence/EloquentStepRunRepository.php` + `src/Models/FlowStepRecord.php`
- Modify: `tests/Contract/PublicApiContractTest.php` (swap `StepRunRepository` → `RunNodeRepository`, `FlowStore::steps` → `runNodes`), `docs/UPGRADE.md` (v1→v2 internal-persistence section: `flow_steps`→`flow_run_nodes`, `StepRunRepository`→`RunNodeRepository`, custom-store migration guidance, the data migration)
- Test: `tests/Unit/Persistence/RunNodeRepositoryTest.php`; **repoint** the existing v1 persistence tests that assert on `flow_steps` (e.g. `FlowEnginePersistenceTest`, replay/prune/approval persistence tests — **VERIFY the full list by grep `flow_steps`**) to `flow_run_nodes`/`node_id`, keeping the ASSERTED BEHAVIOR identical (same statuses, ordering, redaction, atomicity)

**`RunNodeRepository` contract (`@api`):**
```php
interface RunNodeRepository
{
    /** Atomic upsert on (run_id, node_id); prepares a model first so casts serialize (mirror the retired step upsert). */
    public function upsert(string $runId, string $nodeId, array $attributes): void;
    public function markState(string $runId, string $nodeId, NodeState $state, array $attributes = []): void;
    /** @return list<FlowRunNodeView> ordered by sequence then id */
    public function all(string $runId): array;
    public function find(string $runId, string $nodeId): ?FlowRunNodeView;
    /** @return array<string, NodeState> node_id → state, for the coordinator */
    public function states(string $runId): array;
}
```
Redaction: every JSON payload passes the execution-scoped `PayloadRedactor` (LESSON: repository redaction shares the engine's frozen redactor). Upsert atomic on `(run_id, node_id)` (mirror the retired `EloquentStepRunRepository::upsert` — set `created_at`+`updated_at`, exclude only `created_at` from the update columns).

- [ ] **Step 1: Failing tests.** `RunNodeRepositoryTest`: upsert/markState/all/find/states round-trips on sqlite; redaction applied; atomic on duplicate node. Then run the FULL v1 suite → RED where tests still query `flow_steps`.
- [ ] **Step 2–3: Implement** the repository/model/migration, repoint `FlowStore`/`FlowEngine`/read-model/pruner, delete the retired classes, update the repointed v1 tests (behavior identical, table/column names changed).
- [ ] **Step 4: GREEN** — full v1 Unit/Architecture/Contract suite green against the unified table; `PublicApiContractTest` reflects the contract swap; a v1 flow's persisted rows (statuses, order, redaction) match the pre-unification behavior (a golden test comparing the projected `StepSummary` list before/after is the strongest signal — construct it from a fixed fixture flow).
- [ ] **Step 5:** `composer quality`; commit `feat(persistence): unify v1 step persistence onto flow_run_nodes, retire flow_steps`; **close C-PR1** (PR boundary), branch `task/v2c-02-readiness-routing`.

**Edge cases & races (named risks):** the v1 rewire must not change observable execution (compensation order, approval resume, idempotent reuse, prune semantics) — only the persistence target; audit rows that referenced `step_name` now reference `node_id` (keep the audit column name OR rename in the same data migration — DECIDE and document); the data migration must be idempotent and safe on a host that never had `flow_steps` (guard `hasTable`); custom `FlowStore`/`StepRunRepository` implementers are a documented breaking change (UPGRADE) — allowed for the major but MUST be explicit.

**C-PR1 gate criteria:** state-machine matrix exhaustive; `flow_run_nodes` migrates + publishes; the v1 engine writes node rows and the FULL v1 suite is green with assertions repointed and behavior identical; `flow_steps` retired via an idempotent data migration; `RunNodeRepository`/`FlowStore` contract change mirrored in Contract + UPGRADE; `ExecutorApiContractTest` + annotation sweep cover `src/Executor`.

---

## C-PR2 — Readiness resolver, input routing/coalescing, MergeNode

### Task 4: `ReadinessResolver` (Kahn waves) + blocked propagation

**Objective:** Given a `GraphDefinition` and a map of current `NodeState`s, deterministically compute the ready set and the newly-blocked set — proven on diamond and parallel-wave fixtures.

**Files:** Create `src/Executor/ReadinessResolver.php`, `src/Executor/ReadinessDecision.php` (readonly VO: `list<string> $ready`, `list<string> $blocked`, `bool $allTerminal`). Test `tests/Unit/Executor/ReadinessResolverTest.php`.

**Interface:** `final class ReadinessResolver` (`@api`): `resolve(GraphDefinition $graph, array $states): ReadinessDecision` (`$states` = `array<string, NodeState>`; absent nodes = `Pending`). Ready iff `Pending` AND every predecessor is `Succeeded`/`Skipped`. Blocked iff `Pending` AND ≥1 predecessor is `Failed`/`Blocked`/`InvalidInput`/`DeadLetter`. `allTerminal` = every node `isTerminal()`.

```php
public function resolve(GraphDefinition $graph, array $states): ReadinessDecision
{
    $ready = $blocked = [];
    $stateOf = fn (string $id): NodeState => $states[$id] ?? NodeState::Pending;

    $predecessors = [];
    foreach ($graph->connections as $wire) {
        $predecessors[$wire->targetNodeId][] = $wire->sourceNodeId;
    }

    foreach ($graph->topologicalOrder() as $id) {          // deterministic order
        if ($stateOf($id) !== NodeState::Pending) {
            continue;                                       // in-flight or terminal
        }
        $anyPoisoned = false;
        $allSatisfied = true;
        foreach ($predecessors[$id] ?? [] as $p) {
            $ps = $stateOf($p);
            if (in_array($ps, [NodeState::Failed, NodeState::Blocked, NodeState::InvalidInput, NodeState::DeadLetter], true)) {
                $anyPoisoned = true;
            } elseif (! in_array($ps, [NodeState::Succeeded, NodeState::Skipped], true)) {
                $allSatisfied = false;                      // a predecessor is still pending/running
            }
        }
        if ($anyPoisoned) {
            $blocked[] = $id;
        } elseif ($allSatisfied) {
            $ready[] = $id;                                 // roots (no preds) fall here
        }
    }

    $allTerminal = true;
    foreach ($graph->nodeIds() as $id) {
        if (! $stateOf($id)->isTerminal()) { $allTerminal = false; break; }
    }

    return new ReadinessDecision($ready, $blocked, $allTerminal);
}
```

- [ ] TDD: `test_linear_chain_readies_one_at_a_time`, `test_diamond_readies_parallel_wave`, `test_failed_upstream_blocks_not_pends`, `test_skipped_upstream_still_readies_downstream`, `test_mixed_predecessors_block_when_any_failed`, `test_all_terminal_detection`. Commit `feat(executor): readiness resolver with blocked propagation`.

**Edge cases:** roots (no predecessors) ready when Pending; `Running` nodes neither ready nor blocked; terminal nodes never re-readied; ready-list ordering deterministic (topological index) so C-PR5 race tests are reproducible; blocked propagation is transitive across coordinator passes (one level up per pass).

### Task 5: `InputRouter` (connection mapping + coalescing) with the `multiple` port flag

**Objective:** Resolve a node's validated input map from upstream outputs (via connections) and config literals, coalescing multi-source wires ONLY into `multiple: true` ports; signal `invalid_input` (not throw) on validation failure.

**Files:**
- Modify (additive Macro A `@api`): `src/Node/Attributes/Input.php` (+`bool $multiple = false`), `src/Node/PortDefinition.php` (+`bool $multiple = false`, include in `toArray()`), `src/Node/NodeDefinitionFactory.php` (thread the flag; reject `multiple` on scalar ports — must be `Json`/`Any` — with `InvalidNodeDefinitionException`), `src/Node/NodeInputValidator.php` (**VERIFY IN CODE FIRST**: a `multiple` port receives a `list<mixed>`)
- Modify (additive Macro B `@api`): `src/Graph/GraphValidator.php` — anti-fan-in allows N sources into a port iff the target `PortDefinition->multiple`
- Create: `src/Executor/InputRouter.php`, `src/Executor/RoutedInputs.php` (VO: `array $inputs`, `bool $valid`, `?NodeInputValidationException $violation`)
- Test: `tests/Unit/Executor/InputRouterTest.php`; extend `NodeDefinitionFactoryTest`, `GraphValidatorTest`, `tests/Contract/NodeApiContractTest.php` + `GraphApiContractTest.php`; `docs/UPGRADE.md`

**Interface:** `final class InputRouter` (`@api`): `route(NodeDefinition $definition, GraphNode $node, array $connectionsIntoNode, array $upstreamOutputs): RoutedInputs`. For each input port: gather wired values; `multiple` → ordered list (by the source's topological index — deterministic); else the single wired value; else `node->config[portKey]` when present. Run the Macro A `NodeInputValidator`; on failure return `RoutedInputs(valid:false, …)` — the executor maps this to `NodeState::InvalidInput` WITHOUT calling the handler.

- [ ] TDD: factory `test_multiple_flag_threads_to_port_definition`, `test_multiple_on_scalar_port_is_rejected`; validator `test_multiple_source_into_multiple_port_passes`, `test_multiple_source_into_single_port_still_violates` (preserve the existing message); router `test_single_wire_maps_value`, `test_config_literal_satisfies_unwired_port`, `test_multiple_port_coalesces_ordered_list`, `test_validation_failure_returns_invalid_not_throw`, `test_wire_overrides_config_when_both_present` (**wire wins** — document). Commit `feat(node,graph,executor): variadic input ports and connection routing`; mirror `@api` additions in Contract + UPGRADE in this commit.

**Edge cases:** a `multiple` port with zero wires + no config → empty list (valid if not `required`); coalesced order is topological-index of sources (NOT declaration order — pin it); an upstream that produced no value for the named output contributes nothing (no null hole — VERIFY against C-PR3 output recording); config literal used only when a port has no incoming wire.

### Task 6: `MergeNode` fan-in primitive

**Objective:** A registered built-in `flow.merge` node accepts N upstream outputs on one `multiple` input port and emits the coalesced list — the fan-in counterpart to fan-out (C-PR6).

**Files:** Create `src/Executor/Nodes/MergeNode.php`; modify `src/LaravelFlowServiceProvider.php` (register built-in executor node types into the default `NodeRegistry` — **VERIFY IN CODE FIRST** how Macro A wires `nodes.handlers`; add a package built-in list so `flow.merge` etc. are always present). Test `tests/Unit/Executor/Nodes/MergeNodeTest.php`, extend `ExecutorApiContractTest`.

**Interface:** `#[FlowNode(type: 'flow.merge', category: 'control')] final class MergeNode implements FlowNodeHandler` with `#[Input(type: PortType::Json, multiple: true, required: false)] public array $items;` and `#[Output(type: PortType::Json)] public array $merged;`. `execute()` → `NodeResult::success(['merged' => array_values($context->inputs['items'] ?? [])])`.

- [ ] TDD: `test_merge_coalesces_upstream_outputs_into_list`, `test_merge_is_registered_as_builtin`. Commit `feat(executor): MergeNode fan-in primitive`; **close C-PR2**, branch `task/v2c-03-sync-executor`.

**C-PR2 gate criteria:** diamond/parallel-wave planning; failed node → downstream `blocked` (asserted, not silently pending); `multiple` ports coalesce deterministically; single-source anti-fan-in message preserved for normal ports; `flow.merge` built-in; Macro A/B `@api` additions pinned in Contract + UPGRADE.

---

## C-PR3 — Sync graph executor + legacy resolution + version-exact replay

### Task 7: `NodeResolver` (registry + legacy adapter)

**Objective:** Resolve any `GraphNode` to `(NodeDefinition, FlowNodeHandler)` — normal types via registry+container, `legacy.step` via `LegacyStepNodeAdapter` wrapping the container-built v1 step — closing the Macro A legacy-resolution deferral.

**Files:** Create `src/Executor/NodeResolver.php`, `src/Executor/ResolvedNode.php` (VO: `NodeDefinition $definition`, `FlowNodeHandler $handler`). Test `tests/Unit/Executor/NodeResolverTest.php`.

**Interface:** `final class NodeResolver` (`@api`) ctor `(NodeRegistry $registry, Container $container)`; `resolve(GraphNode $node): ResolvedNode`.
- Normal: `$definition = $registry->get($node->type)`; `$handler = $container->make($definition->handlerClass)`.
- Legacy (`$node->type === FlowDefinition::LEGACY_NODE_TYPE`): `$handlerClass = $node->config['handler']`; `$definition = LegacyStepNodeAdapter::definitionFor($node->type, $handlerClass)`; `$handler = new LegacyStepNodeAdapter($container->make($handlerClass))`.
- Unknown non-legacy → propagate `UnknownNodeTypeException`.

- [ ] TDD: `test_resolves_registered_node_from_container`, `test_resolves_legacy_step_via_adapter` (assert `LegacyStepNodeAdapter` + Json ports), `test_unknown_type_throws`, `test_legacy_node_missing_handler_config_throws`. Commit `feat(executor): node resolver with legacy adapter wiring`.

### Task 8: `NodeExecutor` + `GraphRunner` (synchronous) + `Flow::runGraph`

**Objective:** Execute a whole `GraphDefinition` synchronously — readiness loop, per-node route→validate→execute→record via a SHARED `NodeExecutor` (so the queue path in C-PR5 cannot diverge), gate-branch skip, `invalid_input` short-circuit, per-node timing, run-state roll-up — the correctness reference for the coordinator.

**Files:** Create `src/Executor/NodeExecutor.php` (the one place a single node is routed+validated+executed+persisted — used by both sync and queued paths), `src/Executor/GraphRunner.php`, `src/Executor/GraphRunResult.php` (VO: `string $runId`, `RunState $state`, `array<string,NodeState> $nodeStates`, `array<string,array> $nodeOutputs`, `array $errors`). Modify `src/Facades/Flow.php` (+`@method static ... runGraph(...)`, `dryRunGraph(...)`), `src/LaravelFlowServiceProvider.php` (bindings). Test `tests/Unit/Executor/GraphRunnerTest.php`, `tests/Unit/Executor/GraphRunnerLegacyTest.php`, `tests/Unit/Executor/NodeExecutorTest.php`.

**Interface:** `final class GraphRunner` (`@api`) injects `NodeExecutor`, `ReadinessResolver`, `RunNodeRepository`, a clock, `FlowStore`. `run(GraphDefinition $graph, array $input, ?FlowExecutionOptions $options = null, bool $dryRun = false): GraphRunResult`. Loop: `while (! resolve(...).allTerminal)`: persist newly-`Blocked`; for each ready node call `NodeExecutor::execute(...)` (routes; if `!valid` → `InvalidInput`, no handler; else `Running` → handler → `Succeeded`/`Failed`, record outputs/impact/error/timing); safety-net break if no progress and not all terminal. Roll up run state: all `Succeeded`/`Skipped` → `Succeeded`; some `Succeeded` + any poisoned → `PartiallySucceeded`; none succeeded → `Failed`. Dry-run routes `NodeContext(dryRun:true)`; handlers return `dryRunSkipped()`; NO persistence writes (assert zero rows — v1 dry-run parity).

- [ ] **Step 1: Failing tests.** `test_diamond_runs_all_nodes_in_dependency_order`, `test_failed_node_blocks_downstream_and_run_is_partially_succeeded`, `test_invalid_input_short_circuits_before_handler` (invocation-recording fixture asserts NOT run), `test_per_node_timing_recorded`, `test_legacy_step_runs_inside_graph_via_adapter`, and the **equivalence oracle** `test_compiled_v1_flow_matches_v1_engine_output` (compile a v1 3-step flow via `toGraphDefinition()`, run through `GraphRunner`, assert outputs equal `FlowEngine::execute()` for the same input — now especially meaningful since both persist to `flow_run_nodes`).
- [ ] Steps 2–5; commit `feat(executor): synchronous graph runner and shared node executor`.

**Edge cases & races (named risks):** the equivalence oracle is the strongest correctness signal; `invalid_input` MUST NOT run the handler NOR persist outputs; dry-run MUST write zero rows across `flow_runs`/`flow_run_nodes`/`flow_audit` (assert counts); a throwing handler → `Failed` (runner wraps `execute()` in try/catch); roll-up distinguishes `Failed` (nothing completed) from `PartiallySucceeded` (some completed).

### Task 9: Version-exact replay re-execution

**Objective:** `ReplayFlowRunCommand` rewired so a pinned graph run replays the EXACT stored graph version (closing the B-PR7 replay deferral).

**Files:** Modify `src/Console/ReplayFlowRunCommand.php` (**VERIFY IN CODE FIRST** the `definitionDrifted()` seam ≈line 156 + artisan conventions). Test: extend `tests/Unit/Persistence/ReplayFlowRunCommandTest.php`.

**Rewire:** for a run with `definition_version` + `definition_checksum` non-null AND `engine === 'graph'`, load `DefinitionRepository::find($name, $version)`, rebuild `GraphSerializer::fromArray($stored->graph)`, execute THAT graph through `GraphRunner` — genuine version-exact re-execution. Unpinned/legacy runs keep today's behavior; the B-PR7 checksum-aware drift warning stays for the v1 path; a graph-path checksum mismatch vs current `latest()` is INFORMATIONAL (replay uses the pinned one).

- [ ] TDD: `test_pinned_graph_run_replays_stored_version` (persist a run pinned to v1 of a graph, publish v2, assert replay re-executes v1's node set/outputs), `test_unpinned_run_keeps_legacy_replay_behavior`. Commit `feat(executor): version-exact graph replay`; **close C-PR3**, branch `task/v2c-04-retry-timeout`.

**C-PR3 gate criteria:** end-to-end graph runs incl. a v1 step via the adapter (equivalence oracle green); `invalid_input` short-circuits; per-node timing recorded; pinned graph run replays the stored version; dry-run writes zero rows.

---

## C-PR4 — Per-node retry / timeout / dead-letter

### Task 10: `#[Retry]` + graph-level override + `RetryPolicy`

**Objective:** A node's retry/backoff/timeout comes from `#[Retry]`, overridable by graph-node `config`, capped, with exhaustion → `DeadLetter` — proven with a fake clock.

**Files:** Create `src/Executor/Attributes/Retry.php` (`#[Attribute(Attribute::TARGET_METHOD|Attribute::TARGET_CLASS)]`), `src/Executor/RetryPolicy.php` (pure). Modify `src/Node/NodeDefinitionFactory.php` (**VERIFY**: read `#[Retry]` from `execute()`/class; expose additively as `?RetryPolicy $retry` on `NodeDefinition` so the catalog/MCP schema can advertise it), `NodeExecutor` consumes it. Test `tests/Unit/Executor/RetryPolicyTest.php`, `tests/Unit/Executor/RetryExecutionTest.php`.

**Interfaces:** `final class Retry` readonly `(int $tries = 1, int|array $backoff = 0, int $timeout = 0)` (`@api`). `final class RetryPolicy` (`@api`): `fromAttribute(?Retry $a, array $configOverride = []): self`; `tries(): int`, `backoffForAttempt(int $attempt): int` (int=fixed, list=per-attempt with last-value clamp), `timeout(): int`, `isExhausted(int $attempts): bool`. `config['retry']` overrides the attribute.

- [ ] TDD: `RetryPolicyTest`: `test_fixed_backoff`, `test_per_attempt_backoff_list_with_clamp`, `test_config_overrides_attribute`, `test_exhaustion`. `RetryExecutionTest` (fake clock): fail-twice-then-succeed with `tries:3` → `Succeeded`, `attempts`=3; always-fail with `tries:2` → `Failed` then `DeadLetter`; assert `available_at` advances by the backoff. Timeout: exceed `timeout` → failed attempt (**VERIFY** sync enforcement is a post-hoc wall-clock check; preemptive timeout is a queue-worker concern in C-PR5 — document the sync limitation). Commit `feat(executor): per-node retry policy with dead-letter`; **close C-PR4**, branch `task/v2c-05-queue-coordinator`.

**Edge cases:** `tries:0` for a node = single attempt / no retry (recommend; document divergence from Laravel's job-level "0 = unlimited" to avoid a poison node looping forever); backoff list shorter than attempts clamps to the last value; `DeadLetter` only after `tries` exhausted, and it blocks downstream like `Failed`; retry re-entry uses the `Failed → Running` legal edge.

---

## C-PR5 — Queue coordinator + per-node jobs (the race macro)

### Task 11: `CoordinatorJob` + `NodeJob` + `QueueGraphCoordinator`

**Objective:** Run a graph on the queue: a coordinator advances readiness inside a `lockForUpdate` transaction and dispatches independent ready nodes in parallel; each node job executes (via the shared `NodeExecutor`) and re-dispatches the coordinator; duplicate delivery of either job never double-executes or double-completes a node.

**Files:** Create `src/Executor/QueueGraphCoordinator.php`, `src/Executor/Jobs/CoordinatorJob.php` (`@internal`), `src/Executor/Jobs/NodeJob.php` (`@internal`). Modify the `runGraph`/`dispatchGraph` seam, `src/Facades/Flow.php` (+`dispatchGraph`), SP bindings. Test `tests/Unit/Executor/QueueCoordinatorTest.php` (`Queue::fake()` + `PersistenceTestCase`), `tests/Unit/Executor/CoordinatorRaceTest.php`.

**Design (ModelsGenerator gold pattern + the `RunFlowJob` lock blueprint):**

```php
// CoordinatorJob::handle() — advancement serialized by a row lock; nodes claimed by compare-and-set
$connection->transaction(function () use ($runId, $graph, $repo, &$claimed, &$decision): void {
    $connection->table('flow_runs')->where('id', $runId)->lockForUpdate()->first(); // serialize advancement

    $states = $repo->states($runId);                 // array<string, NodeState>
    $decision = $this->readiness->resolve($graph, $states);

    foreach ($decision->blocked as $nodeId) {
        $repo->markState($runId, $nodeId, NodeState::Blocked);
    }

    $claimed = [];
    foreach ($decision->ready as $nodeId) {
        // atomic claim: only the writer that flips pending→running dispatches the node
        $affected = $connection->table('flow_run_nodes')
            ->where('run_id', $runId)->where('node_id', $nodeId)
            ->where('status', NodeState::Pending->value)
            ->update(['status' => NodeState::Running->value, 'started_at' => $this->clock->now()]);
        if ($affected === 1) {
            $claimed[] = $nodeId;                    // won the CAS; $affected === 0 ⇒ another coordinator already claimed it, skip
        }
    }
});
foreach ($claimed as $nodeId) {                      // AFTER commit — nodes never observe uncommitted claims
    NodeJob::dispatch($runId, $nodeId /* + definition ref */)->onQueue($this->queue);
}
if ($decision->allTerminal) {
    $this->finalizeRun($runId);                      // roll up RunState; no re-dispatch
}
```

`NodeJob::handle()` reuses the `RunFlowJob` lock blueprint verbatim: reject `ArrayStore` outside `sync`, require a `LockProvider`, check the completion marker before AND after `->get()`, `release($retry)` on lock-miss without throwing, execute via the shared `NodeExecutor`, persist the terminal node row AND write the completion marker inside the same `finally` (release the lock only when not completed), then `CoordinatorJob::dispatch($runId, …)` to advance.

- [ ] **Step 1: Failing tests.** `QueueCoordinatorTest` (`Queue::fake`): a diamond dispatches `NodeJob` for [a]; simulate a's completion + re-run coordinator → `NodeJob` for [b,c] (parallel); etc. `CoordinatorRaceTest` (deterministic safety-net per LESSON — real concurrency isn't reproducible on shared-connection sqlite): (1) run `CoordinatorJob::handle()` TWICE against the same state → the second dispatches ZERO duplicate `NodeJob`s; (2) run `NodeJob::handle()` TWICE for one node → the second finds the completion marker / terminal state and does NOT re-execute (invocation-count fixture asserts exactly 1); (3) a `NodeJob` losing the cache lock releases for retry without executing.
- [ ] Steps 2–5; commit `feat(executor): queue coordinator and per-node jobs with idempotent dispatch`.

**Edge cases & races (named risks):** the conditional `Pending → Running` UPDATE must constrain on the current status (compare-and-set) so two coordinators can't both claim a node; the node lock store rejects the process-local `array` store outside sync (reuse the `RunFlowJob` guard verbatim); after-commit dispatch (`ShouldQueueAfterCommit`); a crash between "handler succeeded" and "marker written" must, on redelivery, detect the persisted terminal node state and NOT re-run — persist terminal state INSIDE the same transaction as the marker OR make the marker derivable from the row (DECIDE + document); extra coordinator runs are no-ops when nothing is newly ready.

### Task 12: Kill-a-worker recovery + run finalization

**Objective:** A run whose worker dies mid-node recovers (retry per policy or dead-letter) and reaches a correct terminal state; a stuck/blocked run finalizes rather than hangs.

**Files:** Modify `QueueGraphCoordinator`/`NodeJob` (**VERIFY** against the approval-resume "no durable per-step lease" LESSON: without a heartbeat, a retry seeing a node `Running` must NOT re-enter the handler; rely on lock TTL + completion marker; reconcile orphaned `Running` nodes only via an explicit recovery pass, never automatically mid-flight). Test `tests/Unit/Executor/CoordinatorRecoveryTest.php`.

- [ ] TDD: `test_orphaned_running_node_is_not_double_executed`, `test_run_finalizes_when_all_nodes_terminal`, `test_blocked_run_finalizes_partially_succeeded`. Commit `feat(executor): graph run recovery and finalization`; **close C-PR5**, branch `task/v2c-06-subflow-fanout`.

**C-PR5 gate criteria:** duplicate coordinator/node dispatch never double-executes/double-completes (deterministic safety-net tests); killed-worker run recovers without duplicating side effects; parallel independent nodes dispatch in the same wave.

---

## C-PR6 — Sub-flows, fan-out, locked join

### Task 13: `flow_node_children` ledger + locked join

**Objective:** A suspend/join ledger records parent→child relationships; the parent node resumes EXACTLY once when the last child terminates, guarded by a lock.

**Files:** Create `database/migrations/2026_07_09_000010_create_flow_node_children_table.php`, `src/Executor/Persistence/EloquentNodeChildRepository.php` + contract, `src/Models/FlowNodeChildRecord.php`, `src/Executor/JoinCoordinator.php`. Modify SP (`publishesMigrations` + smoke + bindings). Test `tests/Unit/Executor/JoinCoordinatorTest.php`, migration test.

**`flow_node_children` schema:** `id`, `run_id` (parent run), `parent_node_id`, `child_run_id` (36), `child_index` unsignedInt, `status` string(32), `outputs` json nullable, timings; unique `[run_id, parent_node_id, child_index]`; FK `run_id → flow_runs cascadeOnDelete`. Locked join: a terminating child conditionally records its status; `JoinCoordinator` acquires a per-parent-node lock, counts non-terminal children, and only when zero remain flips the parent to `Succeeded` with an aggregated output — the conditional "last one wins" write ensures the parent resumes once under concurrent child completions.

- [ ] TDD (deterministic safety-net): `test_parent_resumes_once_when_last_child_completes`, `test_concurrent_final_children_do_not_double_resume_parent`, `test_child_failure_aggregates_into_parent`. Commit `feat(executor): child-run join ledger with locked join`.

### Task 14: `SubFlowNode`, `ForEachNode` / `MapNode` (maxConcurrency)

**Objective:** Built-in control nodes: run a published flow as a nested child; fan out over a list with bounded concurrency, joining via the Task 13 ledger.

**Files:** Create `src/Executor/Nodes/SubFlowNode.php`, `ForEachNode.php`, `MapNode.php`; register as built-ins. Test `tests/Unit/Executor/Nodes/SubFlowNodeTest.php`, `ForEachNodeTest.php`.

**Interfaces:** `SubFlowNode` config `['flow'=>name, 'version'=>?int]` → loads the published `StoredDefinition`, spawns a child graph run, suspends the parent, records the child. `ForEachNode`/`MapNode` config `['flow'=>name, 'maxConcurrency'=>int]`, input `items` (`multiple`/Json list) → one child per item, capped by `maxConcurrency`, joined into an ordered output list.

- [ ] TDD: `test_subflow_runs_published_flow_as_child`, `test_foreach_fans_out_over_list_and_joins_ordered`, `test_map_concurrency_cap_respected`, `test_child_failure_propagates_to_parent`. Commit `feat(executor): sub-flow and fan-out control nodes`; **close C-PR6**, branch `task/v2c-07-node-cache`.

**C-PR6 gate criteria:** nested flow + fan-out tests green; child-failure aggregation correct; parent resumes exactly once; `maxConcurrency` respected.

---

## C-PR7 — Content-hash node cache

### Task 15: `#[Cacheable]` + `flow_node_cache` + canonical content hash

**Objective:** A `#[Cacheable]` node serves cached outputs on a content-hash hit of `(nodeType, resolvedInputs, nodeConfig)`; dry-run never reads/writes the cache; the hash is stable across input key order.

**Files:** Create `src/Executor/Attributes/Cacheable.php`, `src/Executor/NodeCache.php`, `src/Executor/ContentHasher.php` (pure), `database/migrations/2026_07_09_000011_create_flow_node_cache_table.php`, `src/Models/FlowNodeCacheRecord.php` + repo. Modify SP + the shared `NodeExecutor`. Test `tests/Unit/Executor/ContentHasherTest.php`, `tests/Unit/Executor/NodeCacheTest.php`.

**`flow_node_cache` schema:** `id`, `content_hash` string(64) unique, `node_type`, `outputs` json, `business_impact` json nullable, `created_at`, optional `expires_at`.

**Redaction (CORRECTED — Copilot review, PR #55, round 2):** an earlier draft of this plan said `outputs` should be stored unredacted to avoid a cache hit ever returning a different value than a cache miss would have. That directly contradicts the program's own absolute Global Constraint — "ALL payload persistence flows through the redaction gate" (master plan, and spec §on guardrails) — `flow_node_cache` is a persistence table like any other and gets NO carve-out. The corrected design satisfies BOTH the constraint and the original correctness concern by never letting the two conflict in the first place: `NodeCache::put()` redacts `$outputs` through the SAME `PayloadRedactor` used for `flow_run_nodes`/`flow_audit`, then COMPARES the redacted payload to the raw one — if they differ (the redaction gate actually touched a field, meaning this node's output contains a key on the configured redaction list), the write is SKIPPED entirely (log at debug level; do not cache this invocation) rather than persisting the redacted placeholder as if it were reusable data. If they're identical (the overwhelmingly common case — `#[Cacheable]` is meant for expensive-but-non-secret computations, not secret-producing nodes), the write proceeds normally and a later cache HIT returns exactly what a MISS would have produced, because the stored value never diverged from the raw one to begin with. Net effect: caching and redaction-sensitive output are mutually exclusive by construction — a node whose output happens to contain a redacted-list key simply never benefits from the cache (always recomputes), which is the only choice consistent with "persisted data is redacted" AND "a hit must reproduce what a miss would return." Caching stays opt-in per node via the attribute's presence (same pattern as `#[Retry]`/`#[Cost]`) — a node author who KNOWS a node's output is sensitive shouldn't mark it `#[Cacheable]` regardless (the skip-on-diverge behavior is a safety net, not license to rely on it).

**ContentHasher (reuse the Macro B checksum lesson CAREFULLY — verify which part actually applies):** sha256 over a canonicalized array `['type'=>.., 'inputs'=>.., 'config'=>..]`; recursively sort ASSOCIATIVE/dict keys only. Do **NOT** sort PHP lists here, unlike `GraphSerializer::checksum()`'s graph-structure hashing — that lesson is about graph structure (where node/connection list order is genuinely non-semantic), but node inputs are NOT: C-PR2 defines `multiple` ports as ORDERED lists (coalesced by topological index, explicitly pinned), so `[a, b]` and `[b, a]` must hash differently or a cache hit could return the wrong node's output for a differently-ordered-but-same-set input. `#[Cacheable(ttl: ?int = null)]`.

- [ ] TDD: `ContentHasherTest`: `test_hash_stable_across_input_key_order`, `test_hash_differs_on_value_change`, `test_list_order_semantics_pinned` (document + pin whether input list order is significant — for node inputs it IS: equal lists in equal order hash equal, different order differs). `NodeCacheTest`: `test_miss_then_store_then_hit`, `test_hit_returns_cached_outputs_without_running_handler` (invocation-count = 0 on hit), `test_stored_outputs_pass_through_the_redaction_gate_like_every_other_persisted_payload` (persistence-redaction enabled, output has no redacted-list keys → stored value equals the raw value, proving the cache write genuinely goes through `PayloadRedactor` rather than bypassing it), `test_output_containing_a_redacted_key_is_never_cached` (persistence-redaction enabled, output DOES contain a redacted-list key → `put()` is a no-op, a subsequent lookup is a MISS, and the handler runs again — proving a cache hit can never return a value that diverges from what a miss would have produced, without ever persisting an unredacted secret), `test_dry_run_never_reads_or_writes_cache` (zero cache rows), `test_ttl_expiry`. Commit `feat(executor): content-hash node cache with cacheable attribute`; **close C-PR7**, branch `task/v2c-08-graph-saga`.

**C-PR7 gate criteria:** hit/miss tests; dry-run cache-inert (zero reads/writes asserted); hash stable across input key order; cache hit records `cache_hit` on the node run and skips the handler; cache writes pass through the SAME `PayloadRedactor` as every other persisted payload (no carve-out from the redaction-gate Global Constraint) and a write is skipped (not partially persisted) whenever redaction would have altered the output.

---

## C-PR8 — Graph saga compensation

### Task 16: Reverse-topological compensation + parallel strategy + aggregate compensator

**Objective:** On graph failure, only COMPLETED nodes compensate, in reverse-topological order (or the opt-in parallel strategy), and the reserved `withAggregateCompensator` runs as the final graph-level rollback.

**Files:** Create `src/Executor/GraphSaga.php`; add a graph-node compensation capability (**VERIFY IN CODE FIRST**: prefer a separate `CompensatableNode` interface with `compensate(NodeContext): void` — additive `@api` — over widening `FlowNodeHandler`; legacy nodes compensate via `config['compensator']`). Modify `GraphRunner`/coordinator finalization to trigger the saga. Test `tests/Unit/Executor/GraphSagaTest.php`.

**Interface:** `GraphSaga::compensate(GraphDefinition $graph, array $nodeStates, array $nodeOutputs, string $strategy): void` — reverse of `topologicalOrder()`, filtered to `Succeeded` nodes; `parallel` batches independent compensators (reuse `compensation_parallel_driver` + Laravel Concurrency); aggregate compensator from `metadata['aggregate_compensator']` runs last. Reuse LESSON invariants: don't mark `compensated` until all intended compensators succeed; `parallel` keeps audit/event recording in the parent process; a throwing compensation-event listener must not abort the remaining rollback.

- [ ] TDD: `test_only_completed_nodes_compensate` (failed + blocked NOT compensated), `test_reverse_topological_order_on_diamond` (d before b/c before a), `test_parallel_strategy_batches_independent_compensators`, `test_aggregate_compensator_runs_last`, `test_legacy_node_compensates_via_v1_compensator`. Commit `feat(executor): graph saga compensation`; **close C-PR8**, branch `task/v2c-09-dag-dry-run`.

**C-PR8 gate criteria:** compensation-order tests on DAGs; only completed nodes compensated; aggregate compensator implemented (closes the reserved v0.2 `withAggregateCompensator`); parallel strategy safe.

---

## C-PR9 — DAG dry-run: execution plan + cost estimate

### Task 17: `DryRunPlanner` — waves + cost estimate, zero writes

**Objective:** A dry-run over a `GraphDefinition` produces an execution plan (waves that WOULD run) plus a cost estimate from node-declared hints, writing NOTHING.

**Files:** Create `src/Executor/DryRun/DryRunPlanner.php`, `ExecutionPlan.php` (VO: `list<list<string>> $waves`, `list<string> $skipped`), `CostEstimate.php` (VO: `array $perNode`, `array $total`); add a `#[Cost(estimate: [...])]` attribute read into `NodeDefinition` (**VERIFY**/DECIDE — consistent with `#[Retry]`). Test `tests/Unit/Executor/DryRun/DryRunPlannerTest.php`.

**Interface:** `DryRunPlanner::plan(GraphDefinition $graph, array $input): array{plan: ExecutionPlan, cost: CostEstimate}`. Waves = Kahn layers (wave 0 = roots; wave n = nodes whose predecessors all appear earlier). Cost = sum of per-node hints, per-wave and total.

- [ ] TDD: `test_diamond_planned_in_three_waves` (`[[a],[b,c],[d]]`), `test_cost_estimate_sums_node_hints`, `test_dry_run_plan_writes_nothing` (ZERO rows across `flow_runs`/`flow_run_nodes`/`flow_node_cache`/`flow_node_children`/`flow_audit`), `test_skipped_branch_appears_in_skipped_not_waves` (if skips are only runtime-known, document that dry-run plans the OPTIMISTIC full set). Commit `feat(executor): DAG dry-run plan and cost estimate`; **close C-PR9**, branch `task/v2c-10-approval-gate-node`.

**C-PR9 gate criteria:** correct waves; cost sums hints; zero-write assertions across ALL persistence tables (first-class deliverable).

---

## C-PR10 — Approval gate node on graphs

### Task 18: `ApprovalGateNode` + pause/resume across the coordinator

**Objective:** An `ApprovalGateNode` pauses a graph run (reusing `ApprovalTokenManager`, hash-only storage); the coordinator resumes/rejects correctly across the queue; token semantics unchanged from v1.

**Files:** Create `src/Executor/Nodes/ApprovalGateNode.php`; modify the coordinator/`NodeExecutor` to treat `NodeResult::paused()` as a `Running → Paused` node transition (per the corrected Task 1 state machine) that also drives the run to `RunState::Paused`: persist the pending approval, stop advancing that branch, node row `status='paused'` with `available_at` null — mirroring v1's literal persisted step status for a paused approval gate. Add graph resume/reject: reuse `Flow::resume`/`reject` extended to detect graph runs (**VERIFY** the v1 resume/reject seam + the per-run cache lock; reuse the run-id-keyed lock, NOT token-keyed — LESSON). Test `tests/Unit/Executor/Nodes/ApprovalGateNodeTest.php`, `tests/Unit/Executor/GraphApprovalResumeTest.php`.

- [ ] TDD: `test_approval_gate_pauses_graph_run` (run `Paused`, a pending `flow_approvals` row hash-only, downstream NOT run), `test_resume_advances_the_graph` (resume with the plain token → downstream executes → `Succeeded`), `test_reject_fails_and_compensates` (reject → completed upstream nodes compensate, run `Failed`), `test_token_is_hash_only_in_storage`, `test_duplicate_resume_is_idempotent` (second resume returns current run, no double-advance — reuse the v1 conditional consume + run-id lock). Commit `feat(executor): approval gate node with graph pause/resume`; **close C-PR10**.

**C-PR10 gate criteria:** paused graph run resumes/rejects correctly; token semantics unchanged (hash-only); duplicate resume idempotent; reject compensates completed nodes.

---

## Macro PR & Macro Gate G3 checklist

**Macro PR:** `task/v2c-graph-executor` → `main`, body summarizing the ten subtask PRs + the quality trail (final `composer test` counts); Copilot reviewer via the GraphQL fallback; CI + review loop; a final whole-branch review with the most-capable model BEFORE opening it (mirroring Macro A/B), dispatched with the named race risks aimed at this brief.

Verify item-by-item with commands/evidence before Macro D starts:

- [ ] `composer quality` green on `main` after the macro merge (record Unit/Architecture/Contract counts).
- [ ] The FULL v1 Unit/Architecture/Contract suite is green with persistence assertions repointed to `flow_run_nodes`; v1 EXECUTION behavior is observably unchanged (the before/after `StepSummary`-projection golden test passes); the `RunNodeRepository`/`FlowStore` `@api` change is pinned in `tests/Contract` + documented in `docs/UPGRADE.md`.
- [ ] `flow_steps` is retired via an idempotent data migration that copies existing rows into `flow_run_nodes` and is safe on a host that never had `flow_steps`.
- [ ] Both engines write the unified table: a v1 fluent flow and a graph run both produce `flow_run_nodes` rows readable by the same Dashboard read model with unchanged public DTOs.
- [ ] A nested, fan-out, approval-gated graph runs green on the queue with induced DUPLICATE coordinator/node dispatches — no node double-executes or double-completes.
- [ ] Kill-a-worker-mid-run recovers to a correct terminal state without duplicating side effects.
- [ ] A full DAG dry-run writes NOTHING (zero rows across `flow_runs`/`flow_run_nodes`/`flow_node_cache`/`flow_node_children`/`flow_audit`).
- [ ] The graph saga compensates a mid-graph failure in correct reverse-topological order; only completed nodes compensate; aggregate compensator runs last.
- [ ] A v1 fluent flow compiled to a graph runs through `GraphRunner` and produces outputs equal to `FlowEngine::execute()` for the same input (equivalence oracle).
- [ ] A pinned graph run replays the EXACT stored definition version (version-exact replay closed).
- [ ] `#[Retry]` exhaustion dead-letters a node; `#[Cacheable]` hit skips the handler; `flow.merge` coalesces fan-in.
- [ ] Macro A/B/v1 `@api` additions/changes (`Input`/`PortDefinition` `multiple`; `RunNodeRepository`/`FlowStore`; `#[Retry]`/`#[Cacheable]`/`#[Cost]` on `NodeDefinition`; `CompensatableNode`) mirrored in `tests/Contract` + `docs/UPGRADE.md`; `ExecutorApiContractTest` + annotation sweep cover `src/Executor`.
- [ ] `docs/PROGRESS.md` (dated Macro C completion entry, counts, PR numbers, deferrals resolved, the persistence-unification decision) + `docs/LESSON.md` (race-test patterns, unification learnings, Copilot review lessons) updated.
- [ ] **Macro D detailed plan authored** per the master-plan JIT rule (broadcasting + triggers), grounding on the unified `flow_run_nodes` + progress-counter schema and the per-run channel pattern.
