# Flow Studio — UI/UX Design Brief

> **Purpose of this document:** feed it to a design AI (Claude Design) to produce a complete, production-grade HTML/CSS design template for **Flow Studio**, the web admin panel of the Laravel Flow 2.0 suite. The template will then be implemented as Blade + React (React Flow) by engineers. Deliverables requested at the end.

---

## 1. What we are building

**Flow Studio** is the visual command center of `padosoft/laravel-flow` — a Laravel-native workflow orchestration engine for the agentic era. Users visually **compose** workflows on a node canvas (drag nodes, wire typed ports together), **run** them, **watch them execute live**, **approve** human-in-the-loop gates, inspect **costs and business impact** (including LLM token costs), and receive **AI-generated suggestions** for new or improved workflows.

Think: *"n8n-class canvas, but with an enterprise mission-control soul"* — dry-runs before you commit, saga compensation when things fail, human approval gates, signed webhooks, full audit. The UI must communicate **trust, control and real-time awareness**.

This is a **complete redesign**: an existing read-only console exists (sidebar + topbar + 7 pages + ⌘K command palette) but the designer has full freedom to redefine the visual language. It must remain a serious enterprise tool — distinctive and modern, never toy-like.

## 2. Users

1. **Developer (primary)** — composes flows on canvas, inspects failed runs, reads payloads, iterates on versions. Keyboard-heavy, dark-theme lover, wants density and precision.
2. **Ops / SRE** — watches live runs, retries/cancels, monitors webhook outbox and dead letters, checks KPIs. Needs glanceable status and zero ambiguity.
3. **Business approver** — occasional user; receives an approval request, inspects the impact summary, clicks Approve/Reject. Needs clarity, large affordances, and confidence about what will happen.

## 3. Design goals & personality

- **Mission control**: live, breathing interface — nodes light up as they execute; progress is always visible; nothing feels static.
- **Enterprise trust**: precise typography, restrained color, explicit states, no ambiguity. Dangerous actions look dangerous.
- **Typed & tactile canvas**: ports and wires are color-coded by data type; connecting things feels physical ("pull the wire").
- **Both themes first-class**: light and dark, switchable, equally polished.
- **Dense but calm**: developers want information density; use hierarchy, not clutter.

## 4. Technical constraints (for realistic design)

- Server-rendered shell (Blade) + a **React island** for canvas pages using **React Flow** (@xyflow/react). Design node/edge/canvas visuals as HTML/CSS that map naturally to React Flow custom nodes.
- No external CDN assets at runtime; design tokens as CSS custom properties.
- Existing ⌘K command palette concept must survive (global search + actions).
- Desktop-first admin (min 1280px comfortable, usable at 1024px); no mobile requirement, but nothing should break at tablet width.
- Accessibility: WCAG AA contrast, never color-only signals (pair icons/labels with color), visible focus states, semantic controls. Interactive elements must expose stable identities for E2E tests (data-testid on critical controls).
- Every async action needs visible feedback (spinner/skeleton/toast); every list needs initial/loading/empty/error states designed explicitly.

## 5. Information architecture

```
Sidebar
├─ Overview            (dashboard)
├─ Flows               (library: definitions + versions)
│   └─ Flow detail ────┬─ Studio (canvas editor)   ← the heart
│                      ├─ Versions & diff
│                      ├─ Triggers
│                      ├─ MCP exposure
│                      └─ Runs of this flow
├─ Runs                (all runs, filterable)
│   └─ Run detail      (live canvas monitor + timeline + payloads)
├─ Approvals           (inbox, working approve/reject)
├─ Advisor             (AI suggestions inbox)          ← NEW
├─ Webhooks            (outbox monitor + redeliver)
├─ Settings
⌘K command palette overlays everything
```

## 6. Screen-by-screen requirements

### 6.1 Overview (upgrade of existing)
KPI tiles (total runs, success rate, failed, p95 duration, **total AI cost** period-over-period), throughput chart (stacked success/failed), recent failures, pending approvals, **live "now running" strip** with mini progress bars, advisor teaser card ("3 suggestions for you").

### 6.2 Flows library (replaces "Definitions")
Card or table view of flows: name, version badge (`v7 · published` / `draft` / `archived`), success rate, run count, last run, cost trend sparkline, tags. Primary CTA: **New Flow** (choice: blank canvas / from AI prompt / import JSON). Row actions: open in Studio, run, export.

### 6.3 Studio — canvas editor (THE core screen, design it best)
- **Left: Node palette** — searchable, grouped by category (Control, Data, HTTP, AI, Human, Legacy…), each item with icon + name + port summary; drag onto canvas.
- **Center: Canvas** — dot-grid background, pan/zoom, minimap, zoom controls, fit-view. **Nodes**: card with icon, name, status strip, input ports on left edge / output ports on right edge as colored dots with labels on hover. **Wires**: bezier curves colored by port type; invalid connection attempt renders red with a tooltip explaining the type mismatch. Selected/hover/dragging states. Sub-flow nodes look visually "deeper" (stacked card) and can expand inline or open in a breadcrumbed sub-view.
- **Right: Inspector panel** — properties of the selected node (typed fields per port, required markers, validation errors inline), retry/timeout policy section, cache toggle, per-node docs.
- **Top bar**: flow name + version chip + draft/published state, Undo/Redo, Validate, **Dry-run**, Save draft, **Publish** (confirmation modal explaining immutability), overflow menu (export JSON, duplicate, archive).
- **Validation state**: bottom drawer or side list of graph errors (cycle detected, unwired required input, type mismatch) — clicking one focuses the offending node.
- **Empty state**: friendly "drag your first node or describe your flow to AI" with the two CTAs.

Port type → color mapping (design a legend; suggested, adjust to palette): text=sky, int/float=amber, bool=violet, json=emerald, any=neutral gray, binary=rose. Same colors on wires.

### 6.4 Dry-run / simulation view
Same canvas, read-only overlay: execution **waves** numbered on nodes (1, 2, 2, 3…), skipped branches dimmed, and a right panel with **estimated cost** (per node + total, tokens for AI nodes), estimated duration, and writes-that-would-happen summary. Banner: "Dry run — nothing was written".

### 6.5 Run detail — live monitor
Canvas view of the executed version with **live node states**: pending (neutral), running (pulsing accent border), completed (green check), failed (red + error badge), skipped (dimmed), blocked (striped), invalid_input (orange), paused-awaiting-approval (amber with human icon), cache-hit (green with lightning ⚡), dead-letter (dark red). Progress header: X/Y nodes, %, elapsed, cost so far, correlation id. Side panel tabs: **Timeline** (chronological step list with durations), **Payloads** (input/output JSON viewer with redaction badges `•••`), **Audit**, **Business impact** (aggregated impact + per-node costs), **Errors** (class, message, retry history). Actions: retry failed, cancel, replay (creates linked run), compensate. A live event ticker/terminal at the bottom (collapsible).

### 6.6 Approvals inbox (make it REAL — current one has fake buttons)
Card per pending approval: flow name, step, requested when, expires countdown, **impact summary** (what happens if approved — human-readable), requester/context, link to run monitor. Actions: **Approve** / **Reject** with confirmation modal (optional comment). Expired/consumed states. Empty state ("No approvals waiting — nice.").

### 6.7 Advisor (NEW — AI suggestions inbox)
List of suggestion cards, two kinds:
- **New flow suggestion**: "You could automate X" — rationale chips (based on: available tools, run history), mini-graph thumbnail preview, actions **Open as draft** / Dismiss.
- **Improvement suggestion** for an existing flow: "Add retry+cache to node Y (failed 12× this week, costs €4.2/run)" — severity/benefit badges, **View diff** opens the flow canvas with a visual diff overlay (added nodes glowing green, removed red-dashed, changed amber) and Accept → creates draft version / Dismiss.
Advisor settings row: analysis scope, LLM usage disclosure ("history is redacted before analysis").

### 6.8 Triggers (per flow)
List + create form for three trigger types: **Schedule** (cron with human preview "every day at 06:00"), **Event** (Laravel event class + input mapping), **Inbound webhook** (generated signed URL, secret rotation, last deliveries log). Enable/disable toggles with state feedback.

### 6.9 MCP exposure (per flow)
Security-first design: default OFF. Toggle "Expose as MCP tool" with warning panel; generated tool name + JSON Schema preview (from typed ports); allowed-callers / authorizer note; "approval gates will pause the agent" callout; copyable MCP endpoint config snippet.

### 6.10 Runs list, Webhooks outbox, Settings
Runs: filter bar (status, flow, date, correlation), row = status badge, flow+version, duration, cost, trigger source icon (manual/schedule/event/webhook/MCP). Outbox: status chips with counts, attempts, next retry countdown, **Redeliver** action, payload preview. Settings: config viewer + theme.

### 6.11 AI Flow Builder (modal/dialog)
Prompt textarea ("Describe the workflow…"), streaming generation state, then a **preview canvas** of the proposed graph + list of assumptions; actions: Insert into canvas / Regenerate / Cancel.

## 7. Component inventory to design

Buttons (primary/secondary/danger/ghost + loading), status badges (all node & run states listed in 6.5), port dot + wire styles per type, canvas node card (all states), sub-flow node, palette item, inspector field group with validation error, KPI tile, chart frame, table + filter bar, tabs, toast, confirmation modal (safe vs destructive), drawer, JSON viewer (with redaction style), countdown chip, diff overlay styles (added/removed/changed), empty/loading/error states per pattern, command palette, sidebar + topbar, theme toggle, cost chip (€/tokens), live pulse indicator.

## 8. Realistic data to use in mockups

Node catalog example (palette is generated from this):
```json
{ "schema_version": 1, "nodes": [
  { "type": "http.request", "name": "HTTP Request", "category": "connect",
    "inputs": [{ "key": "url", "type": "text", "required": true }, { "key": "body", "type": "json" }],
    "outputs": [{ "key": "response", "type": "json" }, { "key": "status", "type": "int" }] },
  { "type": "ai.llm", "name": "LLM Prompt", "category": "ai",
    "inputs": [{ "key": "prompt", "type": "text", "required": true }, { "key": "context", "type": "json" }],
    "outputs": [{ "key": "answer", "type": "json" }] },
  { "type": "human.approval", "name": "Approval Gate", "category": "human",
    "inputs": [{ "key": "summary", "type": "text", "required": true }], "outputs": [{ "key": "decision", "type": "bool" }] }
]}
```
Graph shape: `{ "nodes": [{ "id", "type", "position": {x,y}, "config": {} }], "connections": [{ "sourceNodeId", "sourcePortKey", "targetNodeId", "targetPortKey" }] }`.
Live progress event: `{ "node_id", "status", "progress": { "completed": 4, "failed": 0, "total": 9, "percentage": 44 }, "cost_so_far": 0.42 }`.

Use believable flows in mockups, e.g. *"Order refund with approval"* (fetch order → LLM fraud summary → approval gate → refund API → signed webhook) and *"Product image pipeline"* (fetch products → for-each sub-flow → AI image analysis → publish).

## 9. Deliverables requested from Claude Design

1. **Static HTML/CSS template(s)** (self-contained, no CDN) covering at minimum: Studio canvas editor (6.3), Run detail live monitor (6.5), Flows library (6.2), Advisor (6.7), Approvals (6.6), Overview (6.1), plus the AI Builder dialog (6.11) — in **both dark and light** themes with a toggle.
2. A **design tokens** section (CSS custom properties: palette, spacing, radii, typography, elevation, state colors, port-type colors).
3. The **component inventory** (§7) rendered on a dedicated components page with all states.
4. Interactions may be mocked with minimal vanilla JS (theme toggle, tabs, modals, palette search) — no framework needed; engineers will port to Blade+React.
