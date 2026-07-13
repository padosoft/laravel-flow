# Macro F — AI Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Read `docs/LESSON.md` (core repo) end-to-end before writing any code — Macro C and D accumulated load-bearing lessons (queue-coordinator races, saga claim-under-lock crash recovery, PHPStan version skew via `composer update`, broadcast-dispatch-must-not-abort-durable-write ordering, cross-repo dev-branch pinning as "the third location easy to forget", verifying Laravel runtime behavior from source not memory, a `$this`-bound closure route-cache danger, isolating a trigger's failures from the caller's call stack). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The agentic layer (spec §4) as a separate `padosoft/laravel-flow-ai` package: an LLM prompt node, a guardrails/policy engine, an MCP client node, an MCP server exposing flows as tools, a bounded agent node, an AI flow-builder service, and the Flow Advisor (deterministic history analysis → suggestion drafts). **No UI in this macro** — the Advisor's visual diff surfaces in Macro E (E-PR8), which runs after this macro.

**Depends on:** Macro C (graph executor — node contract, `PayloadRedactor`, `NodeContext`/`NodeResult`). Does NOT depend on Macro D (real-time/triggers) — F and D are independent branches off Macro C per the master plan's dependency graph; this macro deliberately runs before Macro E (Studio UI), which depends on both D and F-core.

---

## Grounding notes (verified against the current codebase 2026-07-13, HEAD `main` post-Macro-D)

- **`padosoft/laravel-flow-ai` already exists**, both on GitHub (created 2026-07-07) and as a local sibling checkout at `../laravel-flow-ai` (relative to this repo). Confirmed bootstrap state: identical shape to `laravel-flow-connect`'s pre-D-PR2 snapshot — `composer.json` (PSR-4 `Padosoft\LaravelFlowAI\`, requires `php ^8.3` + `illuminate/support ^13.0`, dev-requires `pint`/`testbench ^11.0`/`phpstan ^2.0`/`phpunit`), one `src/LaravelFlowAIServiceProvider.php`, one trivial `tests/Unit/ServiceProviderTest.php`, **no `.github/workflows/`**. F-PR1's "package bootstrap" deliverable mirrors D-PR2's connect-bootstrap work almost exactly (CI workflow, `composer quality` parity, `require` core) — reuse that PR as a template, including its hard-won lesson: **verify where a shared contract belongs BEFORE implementing it twice.** D-PR2 initially placed the `FlowTrigger` contract in the wrong repo (satellite instead of core) and needed a 3-PR architecture correction across both repos. F-PR1's provider-agnostic LLM client contract has the SAME risk — the master plan doesn't explicitly say which repo owns it, so DECIDE EXPLICITLY and verify against the master plan's exact wording ("Package bootstrap + provider-agnostic LLM client contract... in core" is NOT what the table says — re-read the F-PR1 row yourself; if it's ambiguous, the safer default per the D-PR2 precedent is: contracts that OTHER repos need to implement against, or that core's own graph executor needs to reference (e.g. if an LLM node type is ever registered from core... it isn't, LLM nodes are AI-pack-only), stay local to `laravel-flow-ai` unless a concrete cross-repo consumption need exists. Since nothing outside `laravel-flow-ai` implements or consumes the LLM client contract (unlike `FlowTrigger`, which `laravel-flow-connect` MUST implement against something core defines), it can reasonably live IN `laravel-flow-ai` itself — but confirm this reasoning holds before F-PR1, don't just assume it.
- **No existing LLM/AI abstraction anywhere in core** — `grep -rn "LLM\|anthropic\|openai" src/` (core) returns nothing. This is a green-field capability.
- **`PayloadRedactor` contract** (core, `src/Contracts/PayloadRedactor.php`): a single-method interface, `redact(array $payload): array`. F-PR3's guardrails work reuses this for outbound-payload redaction — the AI pack depends on core's redaction primitive rather than reinventing one, same pattern already established for webhook/audit/cache payloads throughout the program. Read `src/Persistence/ExecutionScopedPayloadRedactor.php` and `KeyBasedPayloadRedactor.php` (core) for the concrete implementation shape you'll either reuse directly (if `laravel-flow-ai` can depend on core, which it will via `require`) or wrap.
- **`NodeContext`/`NodeResult`/`FlowNodeHandler`** (core, `src/Node/`): the node contract every AI-pack node type (LLM node, MCP client node, bounded agent node) implements against, exactly like `laravel-flow-connect`'s triggers implement `FlowTrigger`. Read these fresh before F-PR2 — do not assume the Macro A-era signatures quoted in the Macro C plan's grounding notes are still 100% current; they've been stable since Macro A but re-verify.
- **Node cost hints** (`#[Cost]` attribute, Macro C C-PR9, `src/Executor/Attributes/Cost.php`): F-PR2's "tokens/cost into business impact" deliverable is a DIFFERENT mechanism — `#[Cost]` is a static, declared-at-definition-time estimate for the DRY-RUN planner; an LLM node's actual runtime token/cost usage is dynamic, known only after the call completes, and belongs in `NodeResult::$businessImpact` (the existing runtime-impact channel every node type can populate), NOT in a `#[Cost]` attribute update. Do not conflate the two — verify this distinction holds by reading how `businessImpact` flows through `NodeResult` → persistence → dashboard contracts.
- **MCP (Model Context Protocol)**: no existing MCP client/server code anywhere in this program. F-PR4/F-PR5 are genuinely new integrations — DECIDE which PHP MCP SDK/library (if any mature one exists for PHP as of the implementation date) vs. hand-rolling the JSON-RPC-over-stdio/HTTP wire protocol; research this explicitly at F-PR4 time (do not guess a package name here), and document the decision + why in the PR.
- **Approval gate precedent for the bounded agent's "approval escape hatch"** (F-PR6): reuse `ApprovalGateNode`/`ApprovalTokenManager`/`GraphSaga` compensation exactly as Macro C's C-PR10 built them — an agent node that wants human sign-off before a risky tool call is structurally the SAME primitive as a graph-level approval gate, just triggered from inside a node's own execution rather than as a standalone node type. VERIFY whether this requires a NEW graph-level pause mechanism (a node pausing mid-execution to await approval, then resuming) or whether it decomposes into two ordinary nodes (agent node → approval gate node → continuation) that a flow AUTHOR wires explicitly. The latter is simpler and reuses 100% of existing C-PR10 machinery with zero new pause semantics; the former is more ergonomic for an AI-authored flow but requires new work. Default to the simpler decomposition unless the spec explicitly requires the agent node itself to pause (re-read spec §4's bounded-agent section for the actual requirement before deciding).
- **`FlowAdvisor` (F-PR8) is explicitly core-adjacent but UI-free**: `flow:suggest` + `flow:improve {flow}` are Artisan commands, `FlowAdvisor` is an `@api` service — this is the ONLY F-PR whose consumers include a FUTURE macro (E-PR8's visual diff UI, which reads `FlowAdvisor`'s draft-version output). Design its output shape (the "machine-readable rationale" + draft `GraphDefinition` version) with a UI consumer in mind even though no UI exists yet — a clean, versioned JSON-serializable rationale shape now saves a breaking change when Macro E arrives.
- **History-analysis inputs for F-PR8**: the analyzers read PAST run data — `flow_run_nodes`, `flow_runs`, possibly `flow_audit` (all core, Macro C's unified persistence model). Any history payload handed to an LLM (if F-PR8's "improve" flow ever summarizes history via an LLM call — re-check spec intent, the master plan says analyzers are "deterministic, no LLM" for SUGGESTING, but verify whether `flow:improve`'s NATURAL-LANGUAGE rationale generation is allowed to use an LLM or must also be deterministic/templated) MUST pass through the SAME redaction gate as every other persisted-payload consumer in this program — non-negotiable per this project's `rule-logging-security.md` and the whole program's redaction discipline established since v0.2.

---

## F-PR1 — Package bootstrap + provider-agnostic LLM client contract

**Branch:** `task/v2f-01-bootstrap` in `laravel-flow-ai` (off `main`) — this is the Macro F equivalent of D-PR2's connect-bootstrap; unlike Macro D, there is **no separate "macro branch" step needed in `laravel-flow-ai` itself** since each F-PR is a normal subtask PR straight to `laravel-flow-ai`'s `main` (mirroring how D-PR3/4/5 each merged straight to connect's `main` — connect never got its own macro-branch layer; treat `laravel-flow-ai` the same way). The CORE-repo side of this macro (if any core changes are needed at all — VERIFY, it's plausible F needs zero core changes since nodes are entirely AI-pack-local) uses a macro branch `task/v2f-ai-pack` off `main`, same convention as C/D.

**Objective:** CI-green, `composer quality`-passing package; a provider-agnostic LLM client CONTRACT (interface) with an Anthropic driver and a fake/test driver — no network calls anywhere in the test suite.

**Files (sketch, verify against actual current `laravel-flow-ai` state before implementing):**
- `.github/workflows/ci.yml` — mirror `laravel-flow-connect`'s (already CI-green as of D-PR2) as closely as this package's dependency surface allows.
- `composer.json`: `require` core (local path repo during development, same 3-stage retargeting connect used: dev branch → until Macro F gate → tagged core release — VERIFY which core branch to pin against; if Macro F starts before Macro D's gate fully lands, pin against `task/v2d-realtime-triggers`'s successor or `main` directly if Macro D already merged by then — check `main`'s actual state at F-PR1 implementation time).
- `src/Contracts/LlmClient.php` (or similar): a minimal interface — something like `interface LlmClient { public function complete(LlmRequest $request): LlmResponse; }` — DECIDE the exact request/response value-object shape based on what F-PR2 (LLM node) actually needs (prompt/messages, model, temperature/params, structured-output schema hint, response text + token usage + cost). Design this WITH F-PR2 in mind even though F-PR2 is a separate PR — a contract redesigned mid-macro is expensive, same lesson as `FlowTrigger` needing to be right before D-PR3/4/5 built on it.
- `src/Drivers/AnthropicDriver.php` implementing the contract via a real HTTP client (Guzzle/Laravel HTTP client — check what's already a core dependency, likely `illuminate/http`'s `Http` facade, to avoid adding a new HTTP client dependency).
- `src/Drivers/FakeDriver.php` (or a `tests/Fixtures/` equivalent) — a deterministic, no-network driver for the entire test suite; also a debugging/dev-mode value for host applications' own tests once released.

**Tests:**
- [ ] CI green; a "package boots, provider registers, contract is bound/resolvable" smoke test.
- [ ] Contract test pinning the `LlmClient`/request/response shape (mirrors core's `tests/Contract/` convention, adapted).
- [ ] `test_no_network_calls_in_the_test_suite` — assert (via an HTTP-client fake/spy asserting zero real requests, or by running the whole suite with network access physically blocked in CI if that's feasible) that nothing in this PR's own tests hits a real network endpoint — this is an explicit Macro F acceptance-adjacent guarantee ("all with zero real network in CI") that's cheapest to enforce from F-PR1 onward rather than discovering a leak at the macro gate.

Commit `feat: package bootstrap, provider-agnostic LLM client contract`; **close F-PR1**, branch `task/v2f-02-llm-node`.

**F-PR1 gate criteria:** CI green; contract pinned; no network in test suite.

---

## F-PR2 — LLM node

**Branch:** `task/v2f-02-llm-node` (in `laravel-flow-ai`)

**Objective:** A `FlowNodeHandler` that runs a templated prompt over its input ports, validates structured output against its declared output-port schema, auto-retries (feeding the validation error back into the prompt) on a schema violation, and surfaces tokens/cost into `NodeResult::$businessImpact`.

**Files (sketch):** `src/Nodes/LlmNode.php` (or similar) implementing `FlowNodeHandler` — reads `NodeContext::$inputs`, renders a template (DECIDE the templating mechanism: Blade-in-a-string via `Blade::render()`, a simple `{{key}}` placeholder replacer, or a dedicated micro-templating lib — a host app authoring flows likely wants something Blade-familiar, but a full Blade compile per node execution has overhead/security surface worth weighing; a simple placeholder substitution may be the safer, sufficient default for v1), calls the F-PR1 `LlmClient` contract, validates the response against the node's declared `#[Output]` port types/schema, and on violation re-prompts with the validation error appended (bounded retry count — reuse `#[Retry]`'s existing tries/backoff semantics from core if it fits, or a dedicated smaller retry loop scoped to schema validation specifically since this is a different failure class than infrastructure retry).

**Tests:**
- [ ] `test_schema_violation_retries_with_error_fed_back_then_succeeds` (fake client scripted to fail once, succeed on retry).
- [ ] `test_retry_cap_exhausted_returns_failed` (not dead-letter — this is a data/schema problem, not an infra retry exhaustion; VERIFY against core's `NodeState` vocabulary which state fits, likely plain `Failed`).
- [ ] `test_token_usage_and_cost_land_in_business_impact`.
- [ ] `test_prompt_template_renders_input_ports_correctly`.

Commit `feat: LLM prompt node with schema-validated structured output`; **close F-PR2**, branch `task/v2f-03-guardrails`.

**F-PR2 gate criteria:** fake-client tests: schema-violation → retry-with-error → success; retry cap → failed; cost lands in `business_impact`.

---

## F-PR3 — Guardrails / policy engine

**Branch:** `task/v2f-03-guardrails` (in `laravel-flow-ai`)

**Objective:** Egress allowlist (which external hosts/tools an AI node may reach), automatic redaction of outbound payloads (reusing core's `PayloadRedactor`), rate limits, and per-node-type permissions — enforced BEFORE any network call, not after.

**Files (sketch):** `src/Guardrails/PolicyEngine.php` (or similar) consulted by F-PR2's `LlmNode` and F-PR4's MCP client node before their respective network calls; egress allowlist config-driven (host/domain patterns); redaction wraps outbound payloads through core's `PayloadRedactor` contract (inject the SAME redactor instance/config the host app already configured for core, not a separate AI-pack-local redaction config — one redaction policy per application, not two divergent ones).

**Tests:**
- [ ] `test_policy_denial_blocks_before_any_network_call` (assert the fake HTTP client / `LlmClient` fake was NEVER invoked when policy denies).
- [ ] `test_outbound_payload_is_redacted` (assert the payload actually sent — inspect what the fake client received — has redacted-list keys replaced).
- [ ] `test_rate_limit_denies_after_threshold`.
- [ ] `test_per_node_type_permission_denial`.

Commit `feat: guardrails policy engine — egress allowlist, redaction, rate limits`; **close F-PR3**, branch `task/v2f-04-mcp-client`.

**F-PR3 gate criteria:** policy denial tests; outbound payload asserted redacted; disallowed egress blocked before any network call.

---

## F-PR4 — MCP client node

**Branch:** `task/v2f-04-mcp-client` (in `laravel-flow-ai`)

**Objective:** A node type that connects to an MCP server, selects a tool, maps node input/output ports to the tool's JSON-Schema-described parameters/result.

**Research required before implementation** (per the grounding notes — do not guess): confirm whether a mature PHP MCP client library exists at implementation time, or whether this hand-rolls the JSON-RPC 2.0 wire protocol over MCP's transport (stdio for local servers, HTTP/SSE for remote — check the MCP spec's current transport options). Document the decision explicitly in the PR.

**Files (sketch):** `src/Nodes/McpClientNode.php` implementing `FlowNodeHandler`; an MCP transport/client abstraction underneath (config: server command/URL, tool name, port-to-parameter mapping).

**Tests:**
- [ ] Fake MCP server round-trip (an in-process fake implementing the same wire protocol, no real subprocess/network).
- [ ] `test_tool_error_surfaces_as_typed_node_failure` (not a generic exception — a distinguishable error type/class so a flow author or the Advisor can reason about "this node's MCP tool call failed" specifically).

Commit `feat: MCP client node`; **close F-PR4**, branch `task/v2f-05-mcp-server`.

**F-PR4 gate criteria:** fake MCP server round-trip; tool errors surface as node failure with typed error.

---

## F-PR5 — MCP server: flow-as-tool

**Branch:** `task/v2f-05-mcp-server` (in `laravel-flow-ai`)

**Objective:** Expose a PUBLISHED flow as an MCP tool (port schemas → tool JSON Schema), **disabled by default**, per-flow opt-in + authorizer-gated; a tool call that hits an approval gate pauses correctly (the external MCP caller must see a "pending approval" state, not a hang or a false failure).

**Files (sketch):** `src/Mcp/FlowToolServer.php` (or similar) generating a JSON Schema per published `GraphDefinition`'s input/output ports (reuse `PortDefinition::toArray()`/`PortType` from core — do not hand-roll a second port→JSON-Schema mapping if core already has one anywhere, check `NodeCatalogCommand`/the node-catalog endpoint for a precedent); an authorizer hook (`@api`, deny-by-default — same posture as `DashboardActionAuthorizer`, per this project's non-negotiable rule) gating which flows are tool-exposed; a per-flow opt-in flag (metadata on the `StoredDefinition` or config — DECIDE, likely definition-metadata since it's a property of the flow itself, not deployment config).

**Tests:**
- [ ] Golden tests for generated JSON Schemas (a fixture graph → an exact expected schema, byte-for-byte or structurally asserted).
- [ ] `test_opt_out_flows_are_invisible_to_the_mcp_server` (not listed, not callable, not even a 403 — genuinely absent from the tool listing).
- [ ] `test_approval_pause_resume_integration` (a tool-invoked flow that hits an `ApprovalGateNode` — the MCP caller receives a well-defined "pending" response, and a subsequent resume completes the tool call — reuse Macro C's C-PR10 approval machinery end-to-end here, do not reinvent).

Commit `feat: MCP server exposing published flows as tools`; **close F-PR5**, branch `task/v2f-06-bounded-agent`.

**F-PR5 gate criteria:** golden tests for generated schemas; opt-out flows invisible; approval pause/resume integration test.

---

## F-PR6 — Bounded agent node

**Branch:** `task/v2f-06-bounded-agent` (in `laravel-flow-ai`)

**Objective:** A node that runs an LLM-driven tool-use loop bounded by token/cost/iteration budgets, a tool allowlist (tools may themselves be flows, via F-PR5's flow-as-tool), and an approval escape hatch for risky tool calls.

**Files (sketch):** `src/Nodes/BoundedAgentNode.php` — per the grounding notes, DEFAULT to the simpler decomposition (agent node's own budget/allowlist loop calls tools via F-PR4's MCP client mechanism; a tool call needing human sign-off is either (a) itself a flow exposed via F-PR5 that contains an `ApprovalGateNode`, so the pause is handled entirely by EXISTING C-PR10 machinery with zero new work here, or (b) requires the agent node itself to pause mid-loop — only build (b) if re-reading spec §4 confirms it's required, since (a) is strictly cheaper and reuses proven infrastructure).

**Tests:**
- [ ] `test_budget_exhaustion_halts_with_distinct_state` (not a generic failure — a state a flow author/Advisor can distinguish from "the LLM decided it was done" or "a tool errored").
- [ ] `test_allowlist_violation_blocked` (agent attempts a tool outside its configured allowlist → blocked before the call, not after).
- [ ] `test_loop_transcript_persisted_redacted` (the full reasoning/tool-call transcript goes through the SAME redaction gate as everything else in this program).

Commit `feat: bounded agent node with budgets, tool allowlist, approval escape hatch`; **close F-PR6**, branch `task/v2f-07-flow-builder`.

**F-PR6 gate criteria:** budget-exhaustion halts with distinct state; allowlist violation blocked; loop transcript persisted redacted.

---

## F-PR7 — AI flow builder service

**Branch:** `task/v2f-07-flow-builder` (in `laravel-flow-ai`)

**Objective:** A service that turns a natural-language prompt into a VALIDATED `GraphDefinition` draft — never an invalid draft, always either a passing graph or a typed failure.

**Files (sketch):** `src/Builder/FlowBuilderService.php` — prompts an `LlmClient` (F-PR1) for a structured graph description, maps it to `GraphDefinition`/`GraphNode`/`Connection` (core, Macro B), runs it through core's EXISTING `GraphValidator` (Macro B) before ever returning it — a validation failure becomes a typed result the caller can inspect/retry from, never a silently-broken draft persisted anywhere.

**Tests:**
- [ ] **Property test** (per the master plan's explicit gate criterion — this is unusual rigor for this program, most PRs use example-based tests; check if a property-testing library is already a dev-dependency anywhere in this program's repos, e.g. `eris/eris` or similar for PHP — if none exists, evaluate adding one for this PR specifically, or approximate with a large table of varied fake-LLM-output fixtures if a true property-testing lib isn't warranted for one PR) with a fake LLM: for a wide range of fake LLM outputs (well-formed graphs, malformed JSON, valid JSON but an invalid graph — a dangling connection, a cycle, an unknown node type), the service's output ALWAYS either passes `GraphValidator` or returns a typed failure — NEVER an invalid draft escapes.

Commit `feat: AI flow builder — prompt to validated graph draft`; **close F-PR7**, branch `task/v2f-08-advisor`.

**F-PR7 gate criteria:** property test with fake LLM — output always passes graph validation or returns typed failure, never an invalid draft.

---

## F-PR8 — Flow Advisor core

**Branch:** `task/v2f-08-advisor` (in `laravel-flow-ai`)

**Objective:** Deterministic history analyzers (no LLM — failure hotspots, cost/duration outliers, repeated node-sequence segments, unused declared tools) plus catalog/MCP introspection, producing suggestions as DRAFT `GraphDefinition` versions with machine-readable rationale. `FlowAdvisor` service (`@api`); `flow:suggest` + `flow:improve {flow}` Artisan commands.

**Files (sketch):** `src/Advisor/FlowAdvisor.php` (`@api` — pin in a contract test); analyzer classes (`src/Advisor/Analyzers/*`) each reading `flow_run_nodes`/`flow_runs` history (core, via whatever read-model core already exposes — check `FlowDashboardReadModel`, Macro Task 5, for a precedent read-model to depend on rather than querying raw tables directly, same "route through contracts" discipline this program has followed since v1.0); suggestions are ALWAYS drafts (`StoredDefinition::STATUS_DRAFT`, core), never auto-published — the Advisor proposes, a human (or Macro E's UI, later) accepts/dismisses.

**Tests:**
- [ ] Analyzer unit tests on run-history FIXTURES (deterministic, no LLM, no network) — one test per analyzer type (failure-hotspot, cost/duration outlier, repeated-segment, unused-tool).
- [ ] `test_suggestions_are_always_drafts_never_auto_published`.
- [ ] `test_history_payloads_pass_the_redaction_gate` (whatever history data reaches an LLM, if any — re-verify per the grounding note whether `flow:improve`'s rationale generation uses an LLM at all; if it's purely templated/deterministic, this test may be moot — decide and document).
- [ ] `test_both_commands_emit_rationale_and_draft_version_id` (`flow:suggest` and `flow:improve {flow}`).

Commit `feat: Flow Advisor — deterministic analyzers, suggestion drafts, CLI`; **close F-PR8** — Macro F subtask PRs complete.

**F-PR8 gate criteria:** analyzer unit tests on run-history fixtures (deterministic, no LLM); suggestions always drafts, never auto-published; history payloads to LLM pass redaction gate; both commands emit rationale + draft version id.

---

## Macro F acceptance (G3.2 evidence required before Gate close)

"An external MCP client lists and invokes an opt-in flow, hitting a human approval; the LLM node self-repairs a schema violation; `flow:improve` on a fixture-history flow proposes a retry/cache improvement as a draft version with rationale; all with zero real network in CI. (The advisor's visual diff UI is validated in Macro E / E-PR8.)"

- "MCP client lists and invokes an opt-in flow, hitting approval" — F-PR5's `test_approval_pause_resume_integration` is direct evidence, PROVIDED it genuinely exercises an EXTERNAL-looking MCP client against the server (not just server-internal unit tests) — verify this at the gate, flag if the coverage is server-only.
- "LLM node self-repairs a schema violation" — F-PR2's `test_schema_violation_retries_with_error_fed_back_then_succeeds`.
- "`flow:improve` proposes a retry/cache improvement as a draft with rationale" — this is SPECIFIC (retry/cache, not just "any improvement") — F-PR8's analyzer tests need at least one FIXTURE scenario shaped to genuinely trigger a retry-policy or cache-opportunity suggestion, not just a generic "an analyzer ran" test. Flag at the gate if no fixture specifically proves this exact suggestion type.
- "zero real network in CI" — F-PR1's `test_no_network_calls_in_the_test_suite` plus spot-checking F-PR2/F-PR4/F-PR6's tests all use fakes, not real clients.

---

## Program-level housekeeping for Macro F

- `docs/PROGRESS.md` (core repo — this is where the whole program's progress lives, even for satellite-repo work, per the D-PR3/4/5 precedent): dated section per subtask PR merge, plus a `Macro F CLOSED` section at the gate.
- `docs/LESSON.md` (core repo): record non-obvious discoveries (MCP library choice, templating mechanism choice, property-testing approach, the LLM-client-contract-ownership decision).
- `padosoft/laravel-flow-ai` needs its OWN wow-level README once F-PR1-F-PR8 land (currently only has its 2026-07-07 bootstrap README) — same incremental-drafting note as `laravel-flow-connect`'s README, a G-PR5 program-level deliverable but nothing prevents drafting it PR-by-PR to reduce end-of-program crunch.
- Core `padosoft/laravel-flow` README `Comparison vs alternatives`: once Macro F lands, add rows for LLM/agent/MCP capabilities — conservative competitor research (which of Temporal/Durable Workflow/Symfony Workflow/AWS Step Functions/n8n/Windmill/Inngest have native LLM nodes, MCP support, or an advisor-equivalent — note some of these, e.g. n8n/Windmill/Inngest, aren't in the CURRENT comparison table at all; the master plan's G-PR2 does a full competitor refresh at the very end of the program, so a Macro-F-time addition to the EXISTING table's systems is fine, but don't attempt the full n8n/Windmill/Inngest research here — that's G-PR2's job).
