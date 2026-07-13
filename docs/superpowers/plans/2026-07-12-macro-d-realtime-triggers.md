# Macro D — Real-time & Triggers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Read `docs/LESSON.md` end-to-end before writing any code — Macro C accumulated load-bearing lessons (queue-coordinator races, saga claim-under-lock crash recovery, PHPStan version skew via `composer update`, approval-gate token propagation). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Opt-in broadcasting of graph-run progress (spec §3.5) with a documented payload shape and a hard architecture guarantee that the core package has zero broadcasting dependency when the feature is off; trigger CONTRACTS in core (interfaces only); the actual schedule/event/inbound-webhook trigger IMPLEMENTATIONS live in the sibling `padosoft/laravel-flow-connect` package, which needs its CI/quality bootstrap completed as part of this macro.

**Depends on:** Macro C (graph executor, node/run state machines) — CLOSED 2026-07-12, merged to `main`.

**Macro branch:** per the master plan's macro-branch convention (subtask PRs target the macro branch, not `main` directly), create `task/v2d-realtime-triggers` off `main` FIRST, before opening any D-PR. Every core-repo subtask branch below (D-PR1) branches off `task/v2d-realtime-triggers`, not off `main`. The macro branch itself merges to `main` via a macro PR at the Macro D gate, same as `task/v2c-graph-executor` did for Macro C.

---

## Grounding notes (verified against the current codebase 2026-07-12, HEAD `main` post-Macro-C-merge)

- **No broadcasting today.** `grep -rn "broadcast" config/laravel-flow.php composer.json` returns nothing — this is a genuinely new capability, not a rewire. Laravel's `Broadcasting` component (`illuminate/broadcasting`) is NOT in `composer.json` `require` — it arrives transitively only via `illuminate/support`'s sibling packages pulled in by Orchestra Testbench for tests, so **D-PR1 must explicitly require `illuminate/broadcasting` in `composer.json`** if the package calls its facade/contracts directly (VERIFY the exact minimum surface needed — `Illuminate\Contracts\Broadcasting\ShouldBroadcast` + `Illuminate\Support\Facades\Broadcast` is likely sufficient without pulling a driver; Reverb/Pusher/etc. stay a HOST APP concern, same pattern as the package never requiring a specific queue driver).
- **Config style precedent** (`config/laravel-flow.php`): every optional subsystem gates on an `enabled` boolean read via `env(...)`, e.g. `persistence.enabled`, `webhook.enabled`. `broadcasting.enabled` (default `false`) follows this exactly — same as `webhook`, it should default OFF so a fresh install has zero broadcast side effects.
- **Existing per-run event precedent**: `src/Events/{FlowStepStarted,FlowStepCompleted,FlowStepFailed,FlowPaused,FlowCompensated}.php` are v1 LINEAR-engine events, dispatched only when `audit_trail_enabled` is on (see `FlowEngine.php`'s event-dispatch call sites — grep `event(new Flow` there before D-PR1, the grep above from this shell ran in the wrong file scope, re-run against `src/FlowEngine.php` specifically). These are plain Laravel events (not `ShouldBroadcast`) — broadcasting is a NEW event family for the GRAPH engine's node/run transitions, not a retrofit of these. Do not conflate the two; v1's audit events stay exactly as they are (out of Macro D scope — the master plan's "Scope boundary" pattern from Macro C applies again: this macro adds a new capability to the graph engine, it does not touch v1).
- **`padosoft/laravel-flow-connect` already exists**, both on GitHub (created 2026-07-07) and as a local sibling checkout at `../laravel-flow-connect` (relative to this repo). Confirmed bootstrap state: `composer.json` present (name, PSR-4 autoload `Padosoft\LaravelFlowConnect\`, requires `php ^8.3` + `illuminate/support ^13.0`, dev-requires `pint`, `testbench ^11.0`, `phpstan ^2.0`, `phpunit ^11.5|^12.5`), `README.md`, `LICENSE`, `phpstan.neon`, `phpunit.xml`, `src/`, `tests/` directories exist. **No `.github/workflows/` directory** — D-PR2's "add CI workflow" deliverable is confirmed still needed, not already done. VERIFY current `src/`/`tests/` contents before D-PR2 (they may be empty scaffolding or may have grown since 2026-07-07 — read the actual tree, don't assume empty).
- **Graph run/node state machines** (`src/Executor/State/{NodeState,RunState}.php`, Macro C): both are backed string enums with `isTerminal()`/`canTransitionTo()`. A broadcasting listener for "node transitions" naturally hooks wherever `NodeExecutor::execute()` and `GraphRunner`/`QueueGraphCoordinator` currently persist a node/run state change (`RunNodeRepository::createOrUpdate`, `RunRepository::update`) — VERIFY the exact call sites before D-PR1 and decide: broadcast from the SAME seam both engines already share (`NodeExecutor`/`RunRollup`/the two runners' `persist*` methods), not by re-deriving state elsewhere, so the sync and queued paths can never diverge in what they broadcast (same "one seam, two callers" principle C-PR3 established for execution itself).
- **Approval gate precedent for signed tokens** (`src/ApprovalTokenManager.php`, Macro C's approval work): hash-only storage, TTL, `hashToken()` static. D-PR5's inbound webhook HMAC + timestamp-window scheme should mirror the OUTBOUND webhook scheme already shipped in v0.3 (`src/WebhookDeliveryClient.php` or wherever `X-Laravel-Flow-Signature` is currently generated for `flow:deliver-webhooks` — VERIFY the exact file/class and signature algorithm, likely HMAC-SHA256 over the raw body with a shared secret) so inbound and outbound use the SAME crypto convention, not two different schemes for the same package.

---

## D-PR1 — Broadcasting opt-in

**Branch:** `task/v2d-01-broadcasting` (from `task/v2d-realtime-triggers`, the Macro D branch — create that branch off `main` first if it doesn't exist yet)

**Objective:** When `laravel-flow.broadcasting.enabled` is `true`, graph run/node state transitions broadcast on a per-run private channel with a documented, versioned payload shape; when `false` (default), the core package makes ZERO broadcast dispatches and has no hard runtime dependency on a broadcasting driver being configured.

**Files (VERIFY exact names/seams against current code before implementing — this is a sketch):**
- `config/laravel-flow.php`: add `'broadcasting' => ['enabled' => env('LARAVEL_FLOW_BROADCASTING_ENABLED', false), 'channel_prefix' => env('LARAVEL_FLOW_BROADCASTING_CHANNEL_PREFIX', 'laravel-flow')]` following the existing `webhook`/`persistence` block style exactly.
- `composer.json`: add `illuminate/broadcasting` to `require` (minimum version matching the `illuminate/support ^13.0` pin already there).
- New `src/Broadcasting/` namespace (`@internal` unless a host app genuinely needs to construct these types directly — default to `@internal`, promote to `@api` only if a real consumer need surfaces, mirroring how `Executor\Jobs\*` stayed internal in Macro C):
  - A broadcastable event class per transition family (e.g. `GraphRunProgressUpdated implements ShouldBroadcastNow` or queued `ShouldBroadcast` — VERIFY which fits: a progress stream benefits from `ShouldBroadcastNow` to avoid queue latency stacking on top of the node's own queued job, but confirm this doesn't double-queue under the `sync` driver used in tests).
  - `broadcastOn(): Channel` returns `new PrivateChannel("{$prefix}.run.{$runId}")` — private so a host app's `channels.php` authorization callback gates who can subscribe (document this in the payload/README: the package does NOT ship a default channel authorization — same deny-by-default posture as `DashboardActionAuthorizer`).
  - `broadcastWith(): array` returns the DOCUMENTED payload shape — decide and pin it in this same PR (e.g. `['run_id', 'node_id', 'state', 'sequence', 'occurred_at']` for a node transition; an aggregate snapshot variant with `['run_id', 'status', 'nodes_total', 'nodes_completed', 'nodes_failed', 'progress_pct']` for the run-level summary — spec §3.5 calls for "aggregate progress snapshot payload", so include BOTH a fine-grained per-node event and a coarser run-progress snapshot, or justify collapsing to one if the fine-grained one already carries enough for a client to derive the aggregate).
  - A dispatcher seam bound into the SAME place both `GraphRunner` and `QueueGraphCoordinator` already persist state (per the grounding note above) — gate the dispatch call itself on `config('laravel-flow.broadcasting.enabled')` so disabled means the event class is never even instantiated, not just that Laravel's broadcast manager no-ops (belt-and-suspenders for the "zero dispatches" gate criterion, and avoids constructing a broadcastable object needlessly on the hot path when the feature is off).
- Architecture test extending the existing arch suite (`tests/Architecture/` — check the exact existing test class name, e.g. via the `NodeAnnotationSweepTest`/standalone-agnostic sweep pattern from Macro A/B) asserting `src/` outside `src/Broadcasting/` contains no `Illuminate\Support\Facades\Broadcast` / `Illuminate\Contracts\Broadcasting` references — keeps the dependency contained to one namespace so a future "rip out broadcasting" stays a one-directory change.

**Tests:**
- [ ] `test_broadcasting_disabled_by_default_dispatches_nothing`: `Event::fake()` (or `Broadcast::fake()` if Testbench/Laravel version supports it — VERIFY), run a graph end to end, assert zero broadcast events fired.
- [ ] `test_broadcasting_enabled_dispatches_documented_payload_shape`: enable via config, run a graph, assert the fired event's `broadcastWith()` matches the pinned shape exactly (keys present, types correct) for at least one node transition and the run-progress snapshot.
- [ ] `test_broadcasting_channel_is_private_and_run_scoped`: assert `broadcastOn()` returns a `PrivateChannel` whose name embeds the run id (two different runs → two different channel names, no cross-talk).
- [ ] `test_queued_and_sync_paths_broadcast_identically`: same graph run once via `GraphRunner::run()` and once via `dispatchGraph()` (queued, sync driver in tests) — assert the same SEQUENCE of transition payloads (proves the "one seam, two callers" wiring, not two divergent broadcast call sites).
- [ ] Architecture test above (no Broadcast references outside `src/Broadcasting/`).

Commit `feat(broadcasting): opt-in graph run/node progress broadcasting`; **close D-PR1**, branch `task/v2d-02-connect-bootstrap`.

**D-PR1 gate criteria:** enabled ⇒ events with the documented payload shape (per-node + aggregate snapshot); disabled ⇒ ZERO broadcast dispatches (proven, not assumed); architecture test passes (no hard broadcasting dependency leaking into the rest of `src/`).

---

## D-PR2 — `laravel-flow-connect` CI/quality bootstrap + core wiring

**Branch:** `task/v2d-02-connect-bootstrap` in the `padosoft/laravel-flow-connect` repo (separate repo, separate PR flow — same GitHub org, same review-loop discipline)

**Objective:** Complete the 2026-07-07 scaffold into a real CI-green package: workflow, `composer quality` parity with core, `require`s core (path repo locally / packagist once core v2 tags), and defines the trigger CONTRACTS this macro's D-PR3/4/5 implement against.

**Before implementing:** `cd ../laravel-flow-connect` and read the ACTUAL current `src/`/`tests/`/`composer.json` — the grounding note above confirms the 2026-07-07 snapshot but 5 days is enough time for drift; do not assume it's still empty.

**Files (sketch, verify against actual repo state):**
- `.github/workflows/ci.yml` — mirror `padosoft/laravel-flow`'s own CI workflow (PHP 8.3/8.4/8.5 matrix, Laravel 13, `composer quality`) as closely as the connect package's smaller dependency surface allows; same project rule applies here too — CI triggers on PRs to `main`/`task/**` plus pushes to `main`, never `task/**` push triggers.
- `composer.json`: add `padosoft/laravel-flow` to `require` — during development, a local path repository (`{"type": "path", "url": "../padosoft-laravel-flow"}` or whatever the actual sibling directory name is — confirmed above as `padosoft-laravel-flow`, note the directory name differs from the package name); document in README that a tagged core release replaces the path repo before this package's own first tagged release.
- A `Contracts/` (or similar) namespace defining the trigger interface(s) this macro's D-PR3/D-PR4/D-PR5 implement — e.g. `interface FlowTrigger { public function fire(FlowExecutionOptions $options = null): string; }` or whatever shape actually fits `Flow::dispatch()`'s signature (read `src/Facades/Flow.php` / `FlowEngine::dispatch()` in core first) — **VERIFY/DECIDE this interface shape now, in THIS PR, since D-PR3/4/5 all implement it** (same "define the contract once, implement thrice" discipline as `FlowNodeHandler` in Macro A).

**Tests:**
- [ ] CI green on the wired package (a trivial "package boots, service provider registers" smoke test is enough for THIS PR — the real trigger tests land in D-PR3/4/5).
- [ ] Contract test pinning the trigger interface shape (same `tests/Contract/` convention as core, adapted to this repo).

Commit in `laravel-flow-connect`: `feat: CI bootstrap, core dependency, trigger contract`; **close D-PR2**, branch `task/v2d-03-schedule-trigger` (also in `laravel-flow-connect`).

**D-PR2 gate criteria:** green CI on the wired package; trigger contract(s) pinned by a contract test.

---

## D-PR3 — `ScheduleTrigger`

**Branch:** `task/v2d-03-schedule-trigger` (in `laravel-flow-connect`)

**Objective:** A trigger that registers a cron expression with Laravel's scheduler and fires `Flow::dispatch()` with a mapped input when it runs.

**Files (sketch):** `src/Triggers/ScheduleTrigger.php` implementing D-PR2's contract; a registration seam (likely a config-driven list read in the package's service provider's `withSchedule()`/`Schedule::call()` hook, or a fluent registration API — VERIFY what's more idiomatic given how OTHER Laravel packages that ship scheduled tasks typically expose this, e.g. a `ScheduleTriggerRegistry` the host app's `routes/console.php` or the package's own provider consults).

**Tests:**
- [ ] `test_cron_expression_registers_with_the_scheduler`: Testbench schedule assertions (`$this->app->make(Schedule::class)->events()` — confirm the exact Testbench 11 API for asserting a registered scheduled event's cron expression).
- [ ] `test_scheduled_fire_dispatches_flow_with_mapped_input`: running the scheduled callback dispatches `Flow::dispatch($name, $mappedInput)` — assert via a fake/spy on the core facade or a `Bus::fake()` on the underlying `RunFlowJob` core already dispatches.

Commit `feat(connect): schedule trigger`; **close D-PR3**, branch `task/v2d-04-event-trigger`.

**D-PR3 gate criteria:** cron expression registers correctly; fires `Flow::dispatch` with mapped input.

---

## D-PR4 — `EventTrigger`

**Branch:** `task/v2d-04-event-trigger` (in `laravel-flow-connect`)

**Objective:** Listening for a HOST APPLICATION event creates a flow run with a mapped input; a mapping failure logs the reason and creates NO run (never a half-broken run).

**Files (sketch):** `src/Triggers/EventTrigger.php` + a listener registration mechanism (config-driven map of `event class => [flow name, input mapper]`, registered in the provider's `boot()` via `Event::listen()`).

**Tests:**
- [ ] `test_host_event_creates_a_run_with_mapped_input`: fire a fake host event, assert a run was created with the expected mapped input.
- [ ] `test_mapping_error_creates_no_run_and_logs_reason`: a mapper that throws/returns invalid input → assert zero runs created AND a log entry with the failure reason (use `Log::spy()`, same pattern as Macro C's cache-infrastructure-failure tests).

Commit `feat(connect): event trigger`; **close D-PR4**, branch `task/v2d-05-webhook-trigger`.

**D-PR4 gate criteria:** host event → run created with mapped input; mapping errors → no run + logged reason.

---

## D-PR5 — Inbound `WebhookTrigger`

**Branch:** `task/v2d-05-webhook-trigger` (in `laravel-flow-connect`)

**Objective:** A signed inbound HTTP endpoint that creates a flow run from the request payload, secured by HMAC signature + timestamp window (replay protection), MIRRORING the core's existing OUTBOUND webhook signature scheme (same crypto convention both directions — verify the exact scheme in core before implementing, per the grounding note).

**Files (sketch):** a route + controller (or a route-macro the host app registers, following whatever pattern the outbound side uses for its own signing helper) validating `X-Laravel-Flow-Signature` (or whatever header name core's outbound side already uses — REUSE the name) computed over the raw body + a timestamp header, rejecting: (a) a bad/missing signature → 401, (b) a timestamp outside the configured window (replay protection) → 401, (c) a timestamp within window but a request id/nonce already seen → 401 (VERIFY whether idempotency-key-style replay protection is needed beyond the timestamp window, or whether the window alone is the documented threat model — check how core's OWN webhook delivery documents its retry/idempotency stance and mirror the same risk acceptance).

**Tests:**
- [ ] `test_tampered_signature_is_rejected_401`.
- [ ] `test_expired_timestamp_is_rejected_401`.
- [ ] `test_replay_within_window_is_rejected` (exact mechanism per the VERIFY above).
- [ ] `test_happy_path_creates_a_run`.

Commit `feat(connect): inbound signed webhook trigger`; **close D-PR5** — Macro D subtask PRs complete.

**D-PR5 gate criteria:** tampered signature/expired timestamp rejected (401); replay within window rejected; happy path creates a run.

---

## Macro D acceptance (G3.2 evidence required before Gate close)

"Demo graph run streams node transitions over Reverb in a testbench app; a cron trigger and an inbound signed webhook each start a run; broadcasting fully silent when disabled."

- The "streams over Reverb in a testbench app" clause needs an ACTUAL Reverb-backed (or Testbench's broadcasting-fake equivalent, VERIFY whether a real Reverb server in CI is feasible/desired vs. asserting against `Broadcast::fake()`/`Event::fake()` — a real Reverb integration test may belong in a lightweight demo app rather than the CI matrix; decide and document the decision, don't silently downgrade the acceptance bar without saying so in PROGRESS.md) — flag this explicitly at the Macro D gate rather than asserting it was met if only the fake-based test exists.
- Cron + inbound webhook clauses: D-PR3/D-PR5 tests are direct evidence.
- "broadcasting fully silent when disabled": D-PR1's `test_broadcasting_disabled_by_default_dispatches_nothing` is direct evidence.

---

## Program-level housekeeping for Macro D

- `docs/PROGRESS.md`: dated section per subtask PR merge (same discipline as Macro C), plus a `Macro D CLOSED` section at the gate.
- `docs/LESSON.md`: record any non-obvious discovery (broadcasting driver quirks, Testbench schedule-assertion API surface, HMAC scheme reuse decisions).
- README (`padosoft/laravel-flow` core) `Comparison vs alternatives`: add rows for opt-in broadcasting and triggers once D-PR1/D-PR3/D-PR4/D-PR5 land, per the standing per-PR-README-update rule — conservative competitor research against the same systems already tracked (Durable Workflow, Symfony Workflow, Temporal, AWS Step Functions), fresh snapshot date.
- `laravel-flow-connect` needs its OWN wow-level README once D-PR2-D-PR5 land (it currently has only the 2026-07-07 bootstrap README) — this is a G-PR5 program-level deliverable per the master plan but nothing prevents drafting it incrementally as each trigger lands, reducing the end-of-program crunch.
