# Laravel Flow 2.0 "Super Package" — Design Spec

- **Date:** 2026-07-07
- **Status:** Draft for review
- **Decisions locked with product owner:**
  1. Approach **A** — evolve `padosoft/laravel-flow` in-place to v2.0 (graph engine in core) plus satellite packages; no rewrite, no parallel package.
  2. Studio canvas built on **React Flow** (`@xyflow/react`, MIT), mounted as a React island inside `laravel-flow-admin`; the dev's custom canvas in ModelsGenerator serves as living UX spec and plan B.
  3. Full solid roadmap (Macro A→G); no anticipated MCP proof-of-concept out of order.

---

## 1. Context and goals

Three systems were deep-analyzed on 2026-07-07:

| System | What it is | Verdict |
|---|---|---|
| `padosoft/laravel-flow` v1.0 (this repo) | Linear, PHP-fluent, sync-first flow engine with enterprise gates: native dry-run, saga compensation, approval gates (SHA-256 token), signed webhook outbox, redaction, audit, idempotency, `@api` contract pinning, headless dashboard read-model | Strong governance, weak topology: no DAG/branching/parallel/sub-flows, no declarative format, no definition versioning, no broadcasting, no triggers |
| `laravel-flow-admin` v1.0 | Blade + Alpine read-only console (7 pages, KPIs, timeline, ⌘K) | Mature read-only; mutations are inert (no POST routes), polling is cosmetic, `EloquentReadModel` partially bypasses Dashboard contracts with direct `DB::table` queries, README overclaims (Gantt/DAG/retry/replay not implemented) |
| "Flow v2" in ModelsGenerator (`origin/develop`) | `App\Modules\Platform\Flow` engine + `App\Modules\Flow2` profile: JSON `{nodes[], connections[]}` DAG, event-driven parallel coordinator (Kahn readiness + `lockForUpdate`), suspend/join sub-flows and fan-out (`flow_node_children` ledger), content-hash node cache, Reverb `ShouldBroadcastNow` progress, bespoke React canvas, AI flow builder from prompt | Excellent topology and runtime; weak contracts: untyped/unvalidated node inputs, duplicated palette whitelists, unversioned code registry, `tries=1` + app-level retry cap 2, no `blocked` state, thin security |

**Goal:** merge the two DNAs — Flow v2's topology/runtime patterns re-implemented inside laravel-flow's governance — and add an agentic layer (LLM/MCP/API nodes, flows exposed as MCP tools) that no Laravel competitor has.

**Positioning:** *"The only Laravel-native orchestrator where developers and AI agents compose the same workflows: code-first or visual, with dry-run, saga compensation and human approvals built in — and every flow becomes an MCP tool any agent can call."*

## 2. Package suite (core stays headless and standalone-agnostic)

```
padosoft/laravel-flow          core v2: typed node contracts + graph engine + all existing enterprise gates
padosoft/laravel-flow-ai       LLM node, prompt node, bounded agent node, MCP client node, MCP server (flow-as-tool), AI flow builder, guardrails
padosoft/laravel-flow-connect  generic HTTP/API node, transform/condition/delay/batch utility nodes, triggers (schedule / event / inbound webhook)
padosoft/laravel-flow-admin    becomes "Flow Studio": visual composer (React Flow island) + operating console with working mutations
```

Existing repo rules continue to apply: core headless, dashboard work in the companion, `@api`/`@internal` discipline, `tests/Contract` pinning, README "Comparison vs alternatives" refreshed with fresh competitor research before publishing claims.

## 3. Core v2 design

### 3.1 Node contract (fixes Flow v2's #1 weakness)

A node is a self-describing handler with **enforced** typed ports, declared via PHP attributes. Attributes generate (a) runtime input validation, (b) the JSON catalog entry consumed by the Studio palette, (c) the JSON Schema used when a flow is exposed as an MCP tool.

```php
#[FlowNode(type: 'order.refund', category: 'billing', icon: 'credit-card')]
final class RefundOrderNode implements FlowNodeHandler
{
    #[Input(type: PortType::Int, required: true)]
    public int $orderId;

    #[Input(type: PortType::Money)]
    public ?Money $amount = null;

    #[Output(type: PortType::Json)]
    public array $refundReceipt;

    #[Retry(tries: 3, backoff: [5, 30, 120], timeout: 60)]
    public function execute(NodeContext $ctx): NodeResult { /* ... */ }

    public function compensate(NodeContext $ctx): void { /* saga per node */ }
}
```

- `PortType` enum: `Text, Int, Float, Bool, Json, Money, Binary, Any, …` — extensible; connection validation is type-aware (Studio shows incompatible wires in red; the engine rejects them at publish time).
- Invalid input fails **before** any side effect/provider call, with dedicated node state `invalid_input` (distinct from `failed`).
- Node registry: attribute-driven auto-discovery + explicit registration; one source of truth for palette, executor resolution, and MCP schemas (no hand-maintained whitelists — Flow v2's drift bug class eliminated).
- Existing `FlowStepHandler` (v1) remains supported through an adapter so v1 handlers run unchanged inside v2 graphs.

### 3.2 Graph definition

- `FlowDefinition` becomes a serializable graph: `{ schema_version, nodes[], connections[] }` where a connection is `(sourceNodeId, sourcePortKey, targetNodeId, targetPortKey)` — deliberately compatible with the Flow v2 shape so the dev's flows are importable.
- The v1 fluent API is preserved as sugar: a linear `->step()` chain compiles to a degenerate graph. `approvalGate()`, `compensateWith()`, `withDryRun()` map to node-level metadata.
- Definitions are persistable: new `flow_definitions` table with `name`, `version` (int, monotonic), `status` (`draft|published|archived`), `graph` (json), `checksum`, optional HMAC signature for regulated environments, timestamps, author.
- Every run stores `definition_name + definition_version` (FK to the exact version) — replay becomes honest; "definition drift" warnings disappear.
- Import/export of the JSON envelope (`kind`, `schema_version`) built in.
- Control-flow node types shipped by core: `SubFlowNode` (a node that runs a published flow — nested), `MapNode`/`ForEachNode` (fan-out over a list with `maxConcurrency`), `ConditionNode`/gates, `ApprovalGateNode` (existing approval semantics as a graph node).

### 3.3 Executor (Flow v2's runtime, hardened)

- Queue coordinator job + per-node jobs; readiness via topological analysis inside a `lockForUpdate` transaction; passthrough nodes resolved in-place; independent nodes dispatched in parallel; every finished node re-dispatches the coordinator (event-driven, no blocked workers).
- Suspend/join ledger (`flow_node_children`) for sub-flows and fan-out; locked join on last terminal child; aggregate step on the parent node.
- **Per-node retry policy** (tries/backoff/timeout from attribute or graph JSON), dead-letter status, configurable coordinator/node timeouts (no hardcoded constants).
- Node states: `pending → running → completed | failed | skipped | blocked | invalid_input` (adds `blocked` — downstream of a failure — and `invalid_input`, both missing in Flow v2).
- Run states extend v1: `pending, running, paused (approval), succeeded, partially_succeeded, failed, compensated, aborted`.
- **Dry-run over the DAG**: traverses the graph without side effects, produces an execution plan (which nodes would run, in what waves) plus a cost estimate (from node-declared cost hints); zero writes, as in v1. Unique in the market.
- **Content-hash node cache** (opt-in per node via `#[Cacheable]`): hash of `(nodeType, resolvedInputs, nodeConfig)` → cached outputs; makes replay/retry cheap and saves real money on LLM nodes.
- Saga compensation generalizes to graphs: reverse-topological compensation of completed nodes; `parallel` strategy preserved; aggregate compensator (the reserved v0.2 API) implemented here.

### 3.4 Persistence additions

- New: `flow_definitions` (versioned graphs), `flow_node_children` (join ledger), `flow_node_cache` (content-hash cache).
- Extended: `flow_runs` gains `definition_version`, progress counters; `flow_steps` becomes per-node rows (`node_id`, `node_type`, states above) — migration path documented in UPGRADE guide.
- All payload persistence continues through the existing redaction gate (`laravel-flow.persistence.redaction.enabled`).

### 3.5 Real-time and triggers

- Broadcasting opt-in (`laravel-flow.broadcasting.enabled`): existing events become broadcastable; per-run private channel carries node status transitions + aggregate progress snapshot (Flow v2 pattern). Reverb/Pusher-compatible; core only emits.
- Triggers (shipped in `laravel-flow-connect`, contracts in core): `ScheduleTrigger` (cron), `EventTrigger` (Laravel event → input mapping), `WebhookTrigger` (signed inbound endpoint, mirror of the outbox HMAC scheme).

## 4. `laravel-flow-ai` design

1. **Flow-as-MCP-tool (flagship):** every published flow is exposed as an MCP tool; input/output port schemas become the tool JSON Schema. Approval-gated flows pause the agent until a human approves — production-grade human-in-the-loop for agents, reusing `ApprovalTokenManager` unchanged.
2. **MCP client node:** call external MCP server tools as graph nodes (server config, tool name, port mapping).
3. **LLM node:** provider-agnostic (Anthropic/OpenAI/local), templated prompt over input ports, **structured output validated against the output port schema** with auto-retry feeding the validation error back; token/cost tracked into the existing business-impact projection (extended with `tokens`, `cost`).
4. **Bounded agent node:** LLM+tools loop with hard budgets (tokens/cost/iterations), tool allowlist (tools can be other flows), approval gate as escape hatch.
5. **AI flow builder:** natural-language prompt → validated graph JSON (productizes the dev's prototype).
6. **Guardrails/policy engine:** per-node/per-flow egress allowlists, automatic redaction (reuses `PayloadRedactor`) on payloads leaving to external LLMs, rate limits, per-node-type permissions.
7. **Compensation for AI actions:** saga rollback applies to agentic nodes ("undo for agents").
8. **Flow Advisor (suggest & improve):** an advisory engine that combines (a) the node/tool catalog registered in the host app (including discovered MCP tools), and (b) persisted flow history (run frequencies, failure hotspots, durations, costs, business impact, dead definitions) to produce:
   - **New-flow suggestions:** proposed graph JSON drafts for workflows the user does not have yet but the available tools + usage patterns support.
   - **Improvement suggestions for an existing flow:** concrete graph edits with rationale — e.g., add retry/cache to a flaky/expensive node, insert an approval gate before a high-impact node, extract a repeated segment into a sub-flow, replace a deprecated node, parallelize independent branches.
   Every suggestion is emitted as a **draft `flow_definitions` version** (never auto-published) with a machine-readable rationale payload. Surfaces: **code API** (`FlowAdvisor` service, `@api`), **Artisan commands** (`flow:suggest`, `flow:improve {flow}`), and **Studio UI** (suggestions inbox panel + "Improve this flow" action showing the proposed change as a visual diff on the canvas, accept/dismiss per suggestion). LLM-backed analysis goes through the same guardrails/redaction as other AI nodes; history payloads sent to external LLMs respect the redaction gate.

## 5. Flow Studio (`laravel-flow-admin`)

- **Compose:** React Flow canvas island (Blade shell + mounted React app on Studio routes only); palette generated from the node registry catalog; typed, color-coded wires with live connection validation; node property panels; expandable sub-flows; definition versioning with diff view; dry-run button painting the execution plan + cost estimate on the canvas; JSON import/export; AI builder dialog. The dev's canvas (colors, run overlay, interactions) is the UX reference; his React node-body components are reused where practical.
- **Operate:** current console made real — live run monitor on the canvas via broadcasting; **working** approve/reject (POST routes over `ApprovalTokenManager`), retry/cancel/replay, business-impact + LLM cost panel, outbox redeliver.
- **Hygiene fixes (independent of new features):** close the `DB::table` bypass (all reads through Dashboard contracts, extending them in core where gaps exist), replace cosmetic polling, realign README claims to reality.

## 6. Compatibility, SemVer, security, testing

- **v2.0.0 major** for core: fluent API preserved; new surface annotated `@api`; every `@api` change mirrored in `tests/Contract` and `docs/UPGRADE.md` (existing policy). v1 handlers adapter-supported.
- Laravel 13, PHP 8.3/8.4/8.5 CI matrix unchanged.
- Security: definitions optionally HMAC-signed; deny-by-default authorizers stay; MCP server surface ships **disabled by default** with explicit per-flow opt-in and authorizer checks; inbound webhook trigger requires signature verification; no plain approval tokens recoverable from storage (unchanged).
- Testing: contract tests extended to graph surface; executor tested with fake queue + race simulations (locked join, double-dispatch); dry-run guaranteed write-free by tests; Studio E2E via existing Playwright setup.

## 7. Roadmap (macro branches, in order)

| Macro | Scope | Depends on |
|---|---|---|
| **A — Node Contract & Registry** | Attributes, PortType, enforced validation, auto-discovery registry, JSON catalog, v1 handler adapter | — |
| **B — Graph Definition & Persistence** | Graph model + JSON schema v1, `flow_definitions` versioning, import/export, run→version link, optional signing | A |
| **C — Graph Executor** | Coordinator, parallelism, per-node retry/timeout, `blocked`/`invalid_input` states, suspend/join sub-flows & fan-out, node cache, DAG dry-run + cost plan, graph saga | A, B |
| **D — Real-time & Triggers** | Opt-in broadcasting + progress snapshot; schedule/event/inbound-webhook triggers (connect package bootstrap) | C |
| **E — Flow Studio** | React Flow canvas, palette from registry, live run monitor, working mutations, admin hygiene fixes | B, D |
| **F — AI Pack** | LLM node, MCP client, MCP server (flow-as-tool), bounded agent node, AI builder, guardrails, Flow Advisor (suggest/improve via service + `flow:suggest`/`flow:improve` + Studio suggestions UI with visual diff) | C (E for builder/advisor UI) |
| **G — Release v2.0** | Upgrade guide, migration docs, comparison table with fresh competitor research (n8n, Temporal, Windmill, Inngest, Laravel Workflow/Durable, Symfony), docs-site, contract tests final pass | all |

Each macro follows the established workflow: macro branch + subtask PRs, Copilot review loop, CI green, PROGRESS/LESSON updates.

## 8. Risks and open points

- **Scope size:** this is a program, not a feature; each macro gets its own implementation plan before code. Macro A/B are low-risk foundations and de-risk the rest.
- **Executor concurrency** is the hardest correctness surface (locked joins, idempotent advance); mitigated by porting Flow v2's proven patterns plus dedicated race tests.
- **React island in a Blade package** needs a clean asset pipeline (Vite build shipped with the package); prototype early in Macro E.
- **Competitor drift:** n8n/Windmill move fast on AI; comparison claims re-researched at Macro G per repo rule.
- **Credit:** the ModelsGenerator dev's design is the executive blueprint for Macro C/E; involve him in reviews.
