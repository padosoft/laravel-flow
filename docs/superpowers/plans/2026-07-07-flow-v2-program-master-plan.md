# Laravel Flow 2.0 Program — Master Implementation Plan

> **For agentic workers:** This is the program-level plan. Execute ONE macro at a time via its detailed per-macro plan (see "Plan authoring rule"). Macro A's detailed plan: `docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md`. Use superpowers:subagent-driven-development or superpowers:executing-plans on the per-macro plans, task-by-task.

**Goal:** Deliver the Laravel Flow 2.0 super-package program (graph engine + AI pack + connectors + Flow Studio) per spec `docs/superpowers/specs/2026-07-07-flow-v2-super-package-design.md`.

**Architecture:** Evolve `padosoft/laravel-flow` in-place (approach A): typed node contracts → versioned graph definitions → event-driven graph executor → real-time/triggers → AI pack (LLM/MCP/agent/advisor, core) → Studio (React Flow, penultimate) → v2.0 release. Satellite packages `padosoft/laravel-flow-connect` and `padosoft/laravel-flow-ai` are scaffolded on GitHub and **registered on Packagist** (2026-07-07); they can be required via Composer from Macro D/F onward.

**Macro execution order (updated 2026-07-07 by user decision):** `A → B → C → D → F(core, no UI) → E (Studio UI, PENULTIMATE) → G`. Macro E consumes the UI template produced by Claude Design from the brief `docs/design/2026-07-07-flow-studio-ui-design-brief.md` (the user supplies the downloaded template path — ask for it when starting E if not yet provided). The Advisor/builder UI subtask formerly F-PR9 executes inside Macro E.

**Tech Stack:** PHP ^8.3, Laravel 13 (illuminate ^13.0), PHPUnit 11/12, PHPStan 2, Pint, Orchestra Testbench 11; Studio: React + `@xyflow/react` (React Flow, MIT) + Vite, mounted as island in `laravel-flow-admin` (Blade shell).

## Global Constraints

Copied from spec + repo rules; every task in every macro implicitly includes these:

- PHP `^8.3`; Laravel 13 only (`illuminate/* ^13.0`); CI matrix PHP 8.3/8.4/8.5.
- Core stays **headless and standalone-agnostic** (Architecture suite enforces invariants). No UI in core; dashboard/Studio work only in `laravel-flow-admin`.
- Public surface annotated `@api`, internals `@internal`, never both on one class; every `@api` change mirrored in `tests/Contract` and `docs/UPGRADE.md`.
- v1 fluent API preserved (compiles to degenerate graph); v1 `FlowStepHandler` supported via adapter.
- All payload persistence flows through the redaction gate (`laravel-flow.persistence.redaction.enabled`).
- Deny-by-default authorization everywhere; plain approval tokens never recoverable from storage; MCP server surface disabled by default.
- Workflow: macro branch (`task/v2X-*`) + subtask PRs targeting the macro branch; CI triggers on PRs to `main` and `task/**`; never add `task/**` to push triggers.
- `docs/PROGRESS.md` updated during work; `docs/LESSON.md` when learning something reusable; README "Comparison vs alternatives" updated only with fresh competitor research (Macro G).

---

## Gate system (binding)

Advancement is gate-controlled at three levels. **A red gate is never skipped: iterate (fix → re-run) until green.** If a gate stays red after 3 distinct fix attempts, stop and use superpowers:systematic-debugging before attempt 4.

### G1 — Task Gate (before each commit lands)
1. Definition of Ready declared first: precise objective + implementation details + test guardrails (PHPUnit always; Vitest when JS touched; **Playwright scenarios for EVERY UI/UX interaction introduced** when UI touched).
2. TDD followed: failing test written and observed failing BEFORE implementation.
3. `composer quality` green (`pint --test` + `phpstan` + Unit + Architecture + Contract suites), plus `npm run test` / `npm run build` / `npm run e2e` when frontend touched. Local runtime: **Herd PHP 8.5**.
4. If `@api` surface touched: `tests/Contract` updated in the same commit.

### G1.5 — Local review gate (before every push)
**Local Copilot CLI review loop** (`.claude/skills/local-copilot-review/SKILL.md`): full branch diff vs `origin/main` written to a temp file, reviewed via `copilot --autopilot --yolo -s -p "/review …"`, iterate fix→gates→re-diff→re-review until `NO_FINDINGS`. Only then push.

### G2 — PR Gate (before each subtask PR merges)
1. All G1 + G1.5 gates green on the branch head.
2. Pre-push self-review skill run on the diff; test-count-readme-sync skill run if tests/README counts changed.
3. GitHub Copilot Code Review requested **and verified registered** (GraphQL fallback if needed); loop until all actionable comments resolved (copilot-pr-review-loop skill). Lessons from review comments recorded in `docs/LESSON.md`.
4. CI checks visible and green for the current head (if no checks appear, fix trigger/base per repo rule — do not merge).

### G3 — Macro Gate (before the next macro starts)
1. All subtask PRs of the macro merged; macro branch merged to `main` via macro PR (G2 applies).
2. Macro acceptance checklist (listed per macro below) verified item-by-item with commands/evidence.
3. `docs/PROGRESS.md` updated with macro outcome; lessons captured in `docs/LESSON.md`.
4. The **detailed per-macro plan for the next macro is written and user-reviewed** (see Plan authoring rule).

### Plan authoring rule
Detailed, code-level TDD plans are written just-in-time: Macro A's exists now; each subsequent macro's plan is authored at the preceding Macro Gate (item G3.4), using the then-current codebase as ground truth. This master plan fixes each macro's objective, subtasks, and acceptance gates; the per-macro plan fixes files, code, and step sequences.

---

## Macro A — Node Contract & Registry

- **Branch:** `task/v2a-node-contract` · **Repo:** core · **Depends on:** —
- **Objective:** Typed, self-describing, enforced node contract (spec §3.1): attributes, PortType, validation, registry with auto-discovery, JSON catalog, v1 handler adapter. Purely additive — zero behavior change to v1.
- **Detailed plan:** `docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md`

| Subtask PR | Deliverable | Gate criteria (beyond G1/G2) |
|---|---|---|
| A-PR1 | `PortType` enum + `PortDefinition`; `FlowNode`/`Input`/`Output` attributes | Type-compat matrix tests pass (incl. `Any` and `Int→Float` widening) |
| A-PR2 | `NodeDefinition` + reflection `NodeDefinitionFactory`; `NodeInputValidator` + `NodeInputHydrator` | Malformed classes rejected with precise errors; missing-required & type-mismatch produce per-port violations |
| A-PR3 | `FlowNodeHandler` + `NodeContext`/`NodeResult`; `NodeRegistry` + config + SP wiring | Duplicate type registration throws; registry resolvable from container; non-handler class rejected |
| A-PR4 | `NodeDiscovery` (PSR-4 scan); `NodeCatalog` + `flow:nodes` command | Fixture dir discovery finds exactly the attributed classes; catalog JSON has `schema_version` and full port data |
| A-PR5 | `LegacyStepNodeAdapter`; Contract + Architecture pinning | v1 handler runs through node API (success/failed/dryRunSkipped mapping); new `@api` surface pinned |

**Macro A acceptance checklist:** `composer quality` green; `php artisan flow:nodes --json` (in testbench) emits valid catalog; a v1 `FlowStepHandler` executes unchanged through the adapter; no existing v1 test modified except additive contract pins; PROGRESS updated.

## Macro B — Graph Definition & Persistence

- **Branch:** `task/v2b-graph-definition` · **Repo:** core · **Depends on:** A
- **Objective:** Serializable versioned graph model (spec §3.2): nodes/connections JSON schema v1 (Flow-v2-compatible shape), `flow_definitions` versioning, import/export, run→version pinning, optional HMAC signing, fluent API compiling to graphs.

| Subtask PR | Deliverable | Gate criteria |
|---|---|---|
| B-PR1 | `GraphDefinition`/`GraphNode`/`Connection` VOs + structural validation. Macro-A review follow-ups fold in here: factory-level PHP-property-type vs PortType compatibility check (definition-time, fail-fast); structural sweep test "every src/Node class carries exactly one of @api/@internal"; friendlier duplicate-discovery-root diagnostics; document NodeDefinitionFactory as the sanctioned construction path | Rejects: duplicate node ids, dangling connections, unknown ports, port-type incompatibility (via `PortType::accepts`), cycles (topological check). Accepts diamond DAGs |
| B-PR2 | Canonical JSON schema v1: `fromArray`/`toArray`, envelope `{schema_version, kind, nodes, connections}`, sha-256 checksum | Round-trip property tests (`fromArray(toArray(x)) == x`); unknown `schema_version` rejected; checksum stable across key order |
| B-PR3 | `flow_definitions` migration + `DefinitionRepository` contract (`@api`) with Eloquent impl (`@internal`) | `(name,version)` unique; `draft→published→archived` transitions enforced; published versions immutable (edit → new draft version); sqlite in-memory tests |
| B-PR4 | Optional definition signing (HMAC-SHA256) + verify-on-load | With signing enabled, tampered graph fails load; disabled = no-op; secret handling mirrors webhook outbox pattern |
| B-PR5 | Import/export service + `flow:export`/`flow:import` commands + ModelsGenerator-Flow-v2 shape importer | Fixture of dev's flow JSON imports to valid `GraphDefinition`; export→import round-trip |
| B-PR6 | `FlowDefinitionBuilder` compiles to `GraphDefinition`; `register()` optional persist-as-draft | ALL existing v1 builder/engine tests pass unmodified; linear chain compiles to path graph with approval/compensation metadata preserved |
| B-PR7 | `flow_runs.definition_version` migration + write path + replay pins version | Replay executes the stored version; drift warning only when version unpinned (legacy runs) |

**Macro B acceptance:** v1 regression suite untouched and green; a graph created via JSON and one compiled from fluent API both persist, publish, export, re-import; run rows carry `definition_version`.

## Macro C — Graph Executor

- **Branch:** `task/v2c-graph-executor` · **Repo:** core · **Depends on:** A, B
- **Objective:** Event-driven parallel graph runtime (spec §3.3): coordinator + per-node jobs, per-node retry/timeout, `blocked`/`invalid_input` states, suspend/join sub-flows & fan-out, content-hash cache, DAG dry-run with cost plan, graph saga. Hardest-correctness macro: race tests are first-class deliverables.

| Subtask PR | Deliverable | Gate criteria |
|---|---|---|
| C-PR1 | `NodeState`/`RunState` state machines (adds `blocked`, `invalid_input`, `partially_succeeded`, `dead_letter`) + transition guards | Exhaustive legal/illegal transition tests |
| C-PR2 | Readiness resolver (Kahn) + input routing (connection mapping, coalescing) + blocked propagation | Diamond/parallel-wave planning tests; failed node ⇒ downstream `blocked`, not silent pending |
| C-PR3 | Sync in-memory graph executor (incl. the legacy-node resolution strategy: definitions from `LegacyStepNodeAdapter::definitionFor()` must resolve to the adapter wrapping the container-built v1 step — registry/executor wiring deferred here from Macro A by design) | End-to-end graph runs incl. gate-branch skip; `invalid_input` short-circuits before handler executes; per-node timing recorded; a v1 step runs inside a graph via the adapter |
| C-PR4 | `#[Retry]` attribute + graph-level override: tries/backoff/timeout, `dead_letter` | Fake-clock backoff tests; timeout → failed → retries → dead_letter |
| C-PR5 | Queue coordinator + per-node jobs, `lockForUpdate` advance, idempotent dispatch | Race simulations: duplicate coordinator/node dispatch never double-executes or double-completes (locked join pattern from Flow v2) |
| C-PR6 | `flow_node_children` migration; `SubFlowNode`, `ForEachNode`/`MapNode` (maxConcurrency); locked join | Nested flow + fan-out tests; child failure aggregation; parent resumes exactly once |
| C-PR7 | `#[Cacheable]` + `flow_node_cache` migration + canonical content hash | Hit/miss tests; dry-run never reads/writes cache; hash stable across input key order |
| C-PR8 | Graph saga: reverse-topological compensation, parallel strategy, aggregate compensator (implements reserved `withAggregateCompensator`) | Compensation-order tests on DAGs; only completed nodes compensated |
| C-PR9 | DAG dry-run: execution plan (waves) + cost estimate from node cost hints | Zero-write assertions (v1 pattern) across all persistence tables |
| C-PR10 | `ApprovalGateNode` on graphs (reuses `ApprovalTokenManager`); pause/resume across coordinator | Paused graph run resumes/rejects correctly; token semantics unchanged (hash-only storage) |

**Macro C acceptance:** a nested, fan-out, approval-gated graph runs green on queue with induced duplicate dispatches; kill-a-worker-mid-run recovers; full dry-run of the same graph writes nothing; saga compensates a mid-graph failure in correct order.

## Macro D — Real-time & Triggers

- **Branch:** `task/v2d-realtime-triggers` (core) + bootstrap of `padosoft/laravel-flow-connect` · **Depends on:** C
- **Objective:** Opt-in broadcasting with per-run progress snapshot (spec §3.5); trigger contracts in core; schedule/event/inbound-webhook triggers in `laravel-flow-connect`.

| Subtask PR | Deliverable | Gate criteria |
|---|---|---|
| D-PR1 | Broadcasting opt-in (`laravel-flow.broadcasting.enabled`): broadcastable wrappers, per-run private channel contract, aggregate progress snapshot payload | Enabled ⇒ events with documented payload shape; disabled ⇒ ZERO broadcast dispatches; Architecture test: core has no hard broadcasting dependency |
| D-PR2 | `laravel-flow-connect` completion of bootstrap (repo scaffolded + Packagist-registered on 2026-07-07): add CI workflow, `composer quality` parity, require core, trigger contracts consumed from core | Green CI on wired package; contracts pinned |
| D-PR3 | `ScheduleTrigger` | Testbench schedule assertions: cron expression registers, fires `Flow::dispatch` with mapped input |
| D-PR4 | `EventTrigger` | Host event → run created with mapped input; mapping errors → no run + logged reason |
| D-PR5 | Inbound `WebhookTrigger` (signed endpoint, HMAC + timestamp window mirroring outbox scheme) | Tampered signature/expired timestamp rejected (401), replay within window rejected; happy path creates run |

**Macro D acceptance:** demo graph run streams node transitions over Reverb in a testbench app; a cron trigger and an inbound signed webhook each start a run; broadcasting fully silent when disabled.

## Macro E — Flow Studio (PENULTIMATE — runs after F-core)

- **Branch:** in `laravel-flow-admin` repo (`task/v2e-studio`) · **Depends on:** B, D, F-core (core released as v2-dev tag or path repo)
- **UI template (DELIVERED 2026-07-07):** the user downloaded the Claude Design handoff bundle locally (path recorded in the agent's persistent memory and available from the user on request — not committed here to keep the plan machine-agnostic). First action of Macro E: copy the bundle into the admin repo under `design/claude-design-template/` so it is version-controlled, then read `project/index.html` IN FULL and follow its imports (`app.jsx`, `canvas.jsx`, `flow-data.jsx`, `pages.jsx`, `run-monitor.jsx`, `shell.jsx`, `studio.jsx`, `tweaks-panel.jsx`, `ui.jsx`, `studio.css`, `styles.css`). Per the bundle README: prototypes are mockups — recreate pixel-perfectly in the target stack (Blade shell + React island + React Flow), match visual output, do NOT copy prototype internals, do NOT screenshot. The user delegated the integrate-vs-redo decision: default is full re-skin using the template as visual source of truth (E-PR0 hygiene + rewrite already planned); confirm only if the template conflicts with working-console needs.
- **Objective:** Visual composer (React Flow island) + working operating console (spec §5), plus admin hygiene fixes, plus the Advisor/builder UI (formerly F-PR9). Every UI interaction ships with a Playwright scenario.

| Subtask PR | Deliverable | Gate criteria |
|---|---|---|
| E-PR0 | Hygiene: all reads via core Dashboard contracts (extend core contracts where gaps exist — definitions listing, throughput); remove cosmetic polling; README claims realigned to reality | grep gate: no `DB::table('flow_` in admin adapters; admin test suite green; README contains no unimplemented-feature claims |
| E-PR1 | React island pipeline: Vite build shipped with package, mount point, asset publishing | E2E smoke: canvas page loads with built assets in testbench app |
| E-PR2 | Read-only canvas: render published `GraphDefinition` (nodes from catalog, typed color-coded wires) | Playwright renders fixture graph; wire colors match `PortType` map |
| E-PR3 | Editor: palette from catalog endpoint, drag&drop, connect with live type validation (invalid wire = red, save blocked), node property panels, save-as-draft POST | E2E compose-and-save; server re-validates graph (client validation is advisory); authorizer-gated (deny-by-default proves 403) |
| E-PR4 | Versioning UI: publish, version list, visual diff between versions | E2E publish + diff shows added/removed/changed nodes |
| E-PR5 | Live run monitor: Echo subscription, node state coloring, progress bar, polling fallback | E2E with broadcast stub: node lights up on event; fallback works with broadcasting off |
| E-PR6 | Working mutations: approve/reject (POST via `ApprovalTokenManager`), retry/cancel/replay, outbox redeliver — all behind `ActionAuthorizer` | Feature tests: every mutation 403s by default, succeeds with allowing authorizer; E2E happy paths; inert v1 buttons removed |
| E-PR7 | Dry-run on canvas: execution plan painted (waves), cost estimate panel | E2E dry-run of fixture graph shows plan; API asserts zero writes |
| E-PR8 | Advisor + builder UI (formerly F-PR9): suggestions inbox, "Improve this flow" visual diff on canvas, accept→creates draft / dismiss | E2E: accept creates draft version identical to advisor payload; dismiss persists; authz-gated |

**Macro E acceptance:** full loop demo — compose a flow on canvas, publish, run, watch it live, approve its gate, see costs, accept an advisor suggestion; all mutations authz-gated; admin README truthful; every interaction covered by a Playwright scenario.

## Macro F — AI Pack (core, no UI — runs BEFORE Macro E)

- **Branch:** repo `padosoft/laravel-flow-ai` (`task/v2f-ai-pack`) · **Depends on:** C
- **Objective:** Agentic layer (spec §4): LLM node, guardrails, MCP client + server (flow-as-tool), bounded agent node, AI flow builder, **Flow Advisor** (suggest/improve, service + CLI; its UI ships in Macro E as E-PR8).

| Subtask PR | Deliverable | Gate criteria |
|---|---|---|
| F-PR1 | Package bootstrap + provider-agnostic LLM client contract (Anthropic driver first, fake driver for tests) | CI green; contract pinned; no network in test suite |
| F-PR2 | LLM node: templated prompt over input ports, structured output validated against output port schema, auto-retry feeding validation errors back, tokens/cost into business impact | Fake-client tests: schema-violation → retry-with-error → success; retry cap → failed; cost lands in `business_impact` |
| F-PR3 | Guardrails/policy engine: egress allowlist, automatic redaction (reuse `PayloadRedactor`) on outbound payloads, rate limits, per-node-type permissions | Policy denial tests; outbound payload asserted redacted; disallowed egress blocked before any network call |
| F-PR4 | MCP client node (server config, tool selection, port mapping) | Fake MCP server round-trip; tool errors surface as node failure with typed error |
| F-PR5 | MCP server — flow-as-tool: port schemas → tool JSON Schema; **disabled by default**, per-flow opt-in + authorizer; approval gates pause the tool call | Golden tests for generated schemas; opt-out flows invisible; approval pause/resume integration test |
| F-PR6 | Bounded agent node: token/cost/iteration budgets, tool allowlist (tools may be flows), approval escape hatch | Budget-exhaustion halts with distinct state; allowlist violation blocked; loop transcript persisted redacted |
| F-PR7 | AI flow builder service: prompt → validated `GraphDefinition` draft | Property test with fake LLM: output always passes graph validation or returns typed failure — never an invalid draft |
| F-PR8 | **Flow Advisor core**: deterministic history analyzers (failure hotspots, cost/duration outliers, repeated segments, unused tools) + catalog/MCP introspection → suggestions as **draft** definition versions with machine-readable rationale; `FlowAdvisor` service (`@api`); `flow:suggest` + `flow:improve {flow}` commands | Analyzer unit tests on run-history fixtures (deterministic, no LLM); suggestions are always drafts, never auto-published; history payloads to LLM pass redaction gate; both commands emit rationale + draft version id |

**Macro F acceptance:** an external MCP client lists and invokes an opt-in flow, hitting a human approval; the LLM node self-repairs a schema violation; `flow:improve` on a fixture-history flow proposes a retry/cache improvement as a draft version with rationale; all with zero real network in CI. (The advisor's visual diff UI is validated in Macro E / E-PR8.)

## Macro G — Release v2.0

- **Branch:** `task/v2g-release` · **Repo:** all · **Depends on:** A–F

| Subtask PR | Deliverable | Gate criteria |
|---|---|---|
| G-PR1 | `docs/UPGRADE.md` v1→v2 + data migration guide | Every breaking change listed with before/after code; contract-test diff reconciled |
| G-PR2 | Fresh competitor research (n8n, Temporal, Windmill, Inngest, Laravel Workflow/Durable, Symfony) + README comparison rewrite | Each claim backed by dated research note; snapshot date current |
| G-PR3 | docs-site update (docmd) | `npm run check` + `npm run build` green; new pages in `docmd.config.json` nav |
| G-PR4 | **Knowhow consolidation (mandatory final task)**: re-read `docs/LESSON.md` end-to-end in every touched repo and fold all reusable learnings into rules, skills, `AGENTS.md`/`CLAUDE.md` | Every LESSON entry either folded into a rule/skill or explicitly marked project-specific; diff reviewed |
| G-PR5 | Final audits: contract suite, test-count README sync, wow-level READMEs for connect/ai, CHANGELOGs; tag `v2.0.0` (core) + release tags for connect/ai/admin; GitHub releases | `composer quality` green on all repos; release checklist from v1.0.0 process reused |

**Macro G acceptance = program done:** v2.0.0 published with truthful docs, green CI on the full matrix, the Studio/AI demos reproducible from README instructions, and the knowhow consolidated into the agent operating system (rules/skills/AGENTS).

---

## Progress tracking

- Each macro start/end and each merged subtask PR gets a `docs/PROGRESS.md` entry (this repo) or the satellite repo's PROGRESS.
- This master plan is the single source of truth for sequencing; per-macro plans may re-split subtask PRs with user approval, but gates may only be strengthened, never weakened.
