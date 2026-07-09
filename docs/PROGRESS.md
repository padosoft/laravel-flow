# Progress

## 2026-05-03 - Durable Handoff

This file is a durable handoff summary, not a per-poll CI/Copilot log. Detailed PR iteration history belongs in the relevant GitHub PR.

Known workstreams:

| Workstream | Durable state |
| --- | --- |
| Macro Task 0 - durable agent operating system | Completed after merge of the macro PR to `main`. |
| Macro Task 1 - baseline tooling and Laravel 13 policy | Completed after merge of the macro PR to `main`; Composer/CI/docs now narrow to Laravel 13, PHP 8.3/8.4/8.5, and Composer-script quality gates. |
| Macro Task 2 - v0.2 persistence layer | Completed after merge of the macro PR to `main`; the package has opt-in DB persistence for runs, steps, audit rows, redaction, retention pruning, correlation IDs, and idempotency keys. |
| Macro Task 2 macro review hardening | Centralizes execution-scoped redactor provider resolution, aligns prune transaction callback usage, explicitly deletes pruned child rows, and keeps persistence model PHPDoc aligned with stored timestamp columns so Macro Task 2 review feedback remains folded into the durable implementation. |
| Macro Task 3 - v0.2 queues/replay | Completed after merge of the macro PR to `main`: `Flow::dispatch()` validates and queues an after-commit `RunFlowJob` carrying flow name, input, execution options, per-dispatch lock metadata, and optional guarded Laravel-native tries/backoff metadata; queued jobs release lock-held duplicates after a configurable short delay, no-op completed duplicates, reject process-local `array` locks outside the `sync` queue driver, and have sync/database queue coverage. `flow:replay {runId}` creates new linked terminal-run replays with additive lineage metadata and partial-schema failures. `compensation_strategy=parallel` batches independent compensators while preserving `reverse-order` as the default. |
| Macro Task 4 - v0.3 approval gates/webhooks | Completed after merge of macro PR #32 into `main` (merge commit `7fca1461083d8abb7e054baa32f6b2665a0581f6`). The macro adds `approvalGate($name)` pause primitive, hashed one-time `ApprovalTokenManager` tokens, persisted `Flow::resume()` / `Flow::reject()` with per-run shared cache lock, `flow:approve` / `flow:reject` CLI commands, and `flow:deliver-webhooks` with HMAC-SHA256 signed outbox delivery (lease-based `claimNextPending`, `markDeliveryResult`, configurable timeout/retries). Lifecycle outbox rows (`flow.paused`, `flow.resumed`, `flow.completed`, `flow.failed`) persist in engine transactions. Additive migrations for `flow_approvals` and `flow_webhook_outbox` cascade with `flow_runs`. |
| Macro Task 5 - companion dashboard contracts (package-side) | Completed after merge of macro PR #34 into `main` (merge commit `0191b61f48031e48bc30021e8034bfafba1839ed`). The macro adds the headless `Padosoft\LaravelFlow\Dashboard\*` namespace: `FlowDashboardReadModel` with paginated `listRuns`/`findRun`/`listApprovals`/`pendingApprovals`/`listWebhookOutbox`/`failedWebhookOutbox`/`pendingWebhookOutbox`/`kpis`, immutable read DTOs (`RunSummary`, `StepSummary`, `AuditEntry`, `ApprovalSummary`, `WebhookOutboxSummary`, `RunDetail`, `RunFilter`, `ApprovalFilter`, `WebhookOutboxFilter`, `Pagination`, `PaginatedResult`, `Kpis`), and the `DashboardActionAuthorizer` interface with `DenyAllAuthorizer` registered as the deny-by-default binding (plus `AllowAllAuthorizer` for explicit dev opt-in). The companion app spec lives at `docs/DASHBOARD_APP_SPEC.md` and is intentionally outside the package repo. |
| Macro Task 6 - v1.0 stable API and migration helpers | Completed after merge of macro PR #36 into `main` (merge commit `856f824da61c83bda7e8cc38ec9517a98c32b042`). The macro marks 81 source files with class-level `@api` (Facade, FlowEngine, builder/DTOs, Events, Exceptions, Contracts, Dashboard, WebhookDeliveryClient/Result) or `@internal` (Persistence, Models, Queue, Jobs, Console). Adds `docs/UPGRADE.md`, `docs/MIGRATION_DURABLE.md`, `docs/MIGRATION_SYMFONY.md`, and `tests/Contract/PublicApiContractTest.php` (new `Contract` testsuite) pinning the v1.0 surface so future patches cannot silently drop or rename `@api` classes/methods/constants. `composer test` now runs Unit + Architecture + Contract suites. |

Concurrent subtasks should add rows here instead of replacing existing workstreams.

To resume live work:

- Run `git status --short --branch`.
- Run `gh pr list --state open --json number,title,headRefName,baseRefName,url`.
- For any active PR, verify head, reviewer, mergeability, and CI with `gh pr view <PR> --json headRefOid,mergeable,statusCheckRollup,reviewDecision,reviews`.
- Use `gh api repos/<owner>/<repo>/pulls/<PR>/requested_reviewers`, or derive `<owner>/<repo>` with `gh repo view --json nameWithOwner --jq .nameWithOwner`.

Completed in Macro Task 0:

- Added durable restart files: `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, `docs/LESSON.md`, `docs/PROGRESS.md`, `docs/ENTERPRISE_PLAN.md`, `.github/copilot-instructions.md`, `.claude/skills/laravel-flow-enterprise/SKILL.md`, and `.claude/rules/rule-laravel-flow-enterprise.md`.
- Imported/adapted useful Padosoft Claude pack guidance from the reference project without copying app-specific implementation rules.
- Updated CI so PRs targeting `main` or `task/**` run the matrix; push-trigger CI remains limited to `main` to avoid duplicate subtask runs.
- Recorded the durable rule that README section `Comparison vs alternatives` must be reviewed for every new or materially improved feature, with competitor research when claims are uncertain.
- Aligned README, CONTRIBUTING, PR template, Copilot instructions, repo rules, and repo skills around the macro/subtask workflow, the pre-Macro-1 Laravel 12/13 compatibility state, companion-dashboard scope, and mandatory Copilot review.

Macro Task 0 validation summary:

- Macro Task 0 was validated with:
  - `composer validate --strict --no-check-publish`
  - `vendor/bin/pint --test`
  - `vendor/bin/phpstan analyse --no-progress`
  - `vendor/bin/phpunit --testsuite Unit` => 32 tests, 97 assertions
  - `vendor/bin/phpunit --testsuite Architecture` => 2 tests, 7 assertions

Completed in Macro Task 2 (v0.2 persistence layer):

- Added one publishable migration file that creates `flow_runs`, `flow_steps`, and `flow_audit` with SQLite-tested schema and MySQL/Postgres-friendly indexes.
- Added public `FlowStore`, `RunRepository`, `StepRunRepository`, `AuditRepository`, `RedactorAwareFlowStore`, and `CurrentPayloadRedactorProvider` contracts.
- Added Eloquent-backed persistence repositories with redacted JSON payload storage, append-only audit protections, immutable run identity updates, and atomic step upserts.
- Wired the synchronous engine to persist opt-in run/step/audit transitions, business impact, output aggregates, failures, compensation state, timestamps, durations, correlation IDs, and idempotency keys.
- Added `FlowExecutionOptions` for normalized, length-validated correlation/idempotency metadata and idempotent persisted-run reuse with step-result rehydration and create-race fallback.
- Added `flow:prune` retention cleanup for old terminal runs while keeping pending/running rows intact.

Current active macro:

- Macro Task 7 — release docs + v1.0.0 tag (`task/release-docs-v1`). Adds `CHANGELOG.md`, expands README architecture/security/enterprise sections, folds reusable `docs/LESSON.md` findings back into AGENTS / CLAUDE / RULES / Copilot instructions / PR template / repo skills, and tags `v1.0.0` from `main` after the macro PR merges.

Current validation baseline:

- `composer validate --strict --no-check-publish`
- `composer format:test`
- `composer analyse`
- `composer test` => Unit 250 tests / 1125 assertions, Architecture 2 tests / 7 assertions, Contract 63 tests / 265 assertions

## 2026-06-21 - CI Compatibility Fix

- CI compatibility fix prepared from `main` for the next patch release.
- Fixes the CI fatal in `FlowEngineCompensationTest` by matching Laravel's current `Illuminate\Contracts\Concurrency\Driver::run()` timeout signature on the test double.
- Expands the GitHub Actions PHP matrix to 8.3, 8.4, and 8.5.

## 2026-07-07 - Flow 2.0 Super-Package Program Kickoff (planning)

- Deep comparative analysis completed across `padosoft/laravel-flow` v1.0, `laravel-flow-admin`, and the Flow v2 prototype in ModelsGenerator (`origin/develop`).
- Approved design spec: `docs/superpowers/specs/2026-07-07-flow-v2-super-package-design.md` (approach A: evolve core in-place; React Flow canvas; AI pack incl. Flow Advisor suggest/improve).
- Program master plan with gate system (Task/PR/Macro gates) and Macros A-G: `docs/superpowers/plans/2026-07-07-flow-v2-program-master-plan.md`.
- Macro A (Node Contract & Registry) detailed TDD plan ready for execution: `docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md`.
- Next action: execute Macro A on branch `task/v2a-node-contract` (5 subtask PRs).

## 2026-07-07 - Program Automode Kickoff (session 2)

- User confirmed approach A, React Flow canvas, full roadmap; automode authorized through 100% completion.
- Studio UI design brief delivered for Claude Design: `docs/design/2026-07-07-flow-studio-ui-design-brief.md`. User will supply the generated template path; Macro E (Studio UI) moved to PENULTIMATE position (order A-B-C-D-F(core)-E-G; ex F-PR9 advisor UI now E-PR8).
- Satellite packages scaffolded, pushed and tagged v0.0.1: github.com/padosoft/laravel-flow-connect and github.com/padosoft/laravel-flow-ai. User registered BOTH on Packagist (confirmed).
- Working mode codified (mandatory): `.claude/rules/rule-flow-v2-program-workflow.md` (DoR/DoD, Playwright guardrails on UI, Herd PHP 8.5, LESSON-to-subagents, knowhow consolidation final task) + new skill `.claude/skills/local-copilot-review/SKILL.md` (local Copilot CLI loop before every push; CLI 1.0.68 flags verified). AGENTS.md / CLAUDE.md updated; master plan updated with G1.5 gate and new macro order.
- Reference-repo inventory (product_image_discovery_admin) captured in LESSON.md 2026-07-07 entries.
- Next step: start Macro A on `task/v2a-node-contract` per `docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md` (subagent-driven, 5 subtask PRs).

## 2026-07-08 - Macro A (Node Contract & Registry) — implementation complete

- All 10 tasks of `docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md` implemented on macro branch `task/v2a-node-contract` via 5 subtask PRs, ALL MERGED (#41, #42, #43, #44, #45).
- Delivered: `Padosoft\LaravelFlow\Node\` namespace — PortType/PortDefinition, attributes (FlowNode/Input/Output with fail-fast guards), reflection NodeDefinitionFactory (hydratability + default-value + instance-only contracts), NodeInputValidator/Hydrator (null semantics, reserved `_` keys), FlowNodeHandler + NodeContext/NodeResult (1:1 FlowStepResult parity), NodeRegistry (typed exceptions with payloads), PSR-4 NodeDiscovery (root-safe offsets, fail-fast on malformed nodes, config-over-discovery precedence), NodeCatalog + `flow:nodes`, LegacyStepNodeAdapter.
- Quality trail: every subtask PR passed local Copilot CLI review loops (25 findings fixed pre-push across the macro), internal SDD task reviews, PR-level Copilot review and CI on PHP 8.3/8.4/8.5.
- Gates at completion (branch head 9e17195 + doc fixes): `composer quality` green — Unit 316/1310, Architecture 3/9, Contract 69/324, verified live on Herd PHP 8.5.7.
- Final whole-branch review (45 commits, most-capable model): code merge-ready; doc syncs applied on the branch (UPGRADE.md Node-namespace @api bullet with explicit stability note, this PROGRESS refresh, docs-site deferral below). PR-level bot findings fixed along the way: definitionFor runtime guards, v1 throw-to-fail semantics preserved in the adapter.
- docs-site decision: v2 Node-contract documentation (node authoring guide, `flow:nodes`) is DEFERRED to Macro G G-PR3 per master plan — the surface is a v2 preview with no end-user flow-authoring path until the graph engine (Macros B/C) lands; recorded here and in the macro PR description to satisfy rule-docmd-docs-sync explicitly.
- Follow-ups folded into Macro B/C planning: factory-level PHP-property-type vs PortType compatibility check (biggest remaining host-app hole), friendlier duplicate-root diagnostics, NodeDefinition constructor-bypass note, structural every-class-annotated sweep test.
- Next: macro PR `task/v2a-node-contract` -> main, G3 checklist, Macro B detailed plan.

## 2026-07-08 - Macro B B-PR5 (Tasks 8+9): definition export/import + Flow-v2 importer

- Branch `task/v2b-05-import-export` (off `task/v2b-graph-definition`, after B-PR1-4 merged). Adds `Padosoft\LaravelFlow\Graph\GraphTransfer` (`@api`: `export(StoredDefinition): string` pretty JSON envelope + `definition` provenance block; `importDraft(string $json, string $name): StoredDefinition` — strips the provenance block, validates via `GraphValidator`, persists via `DefinitionRepository::createDraft()`), `flow:export`/`flow:import` Artisan commands (`@internal`, `src/Console`), and `Padosoft\LaravelFlow\Graph\Flow2Importer` (`@api`) for the ModelsGenerator "Flow v2" prototype shape (envelope or bare `{nodes, connections}` config; `serviceType`->type, `data`->config; drops the source app's connection `id`; structural-only validation, semantic validation deferred to the caller/publish).
- `flow:import` gained a `--format=flow2` option wired to `Flow2Importer`; that path skips `GraphValidator` deliberately (the source `serviceType` catalog is foreign to the local `NodeRegistry`) and relies on `DefinitionRepository::publish()`'s existing semantic validation as the enforcement point once matching node types are registered locally.
- Deviations from the thin task briefs, decided and documented in code/tests: the export command's version-selection option is `--definition-version=` (not `--version=`) because Symfony Console's base `Application` already reserves `--version`/`-V`; `flow:import`'s draft name resolves from `--name`, else a `metadata.name` key, else a top-level `name` key in the imported JSON (documented as `flow:import`'s own error message when none resolve). Importer class name/fixture location follow the task-dispatch instructions (`Flow2Importer`, `tests/Fixtures/flow2/descrivi-immagine.json`) rather than the plan doc's `ModelsGeneratorFlowImporter`/`tests/Fixtures/Graph/modelsgenerator-flow2.json` naming.
- docs-site: NOT updated in this PR, following the same reasoning as Macro A's deferral — export/import and the Flow-v2 importer are still v2-preview surfaces with no end-user flow-authoring path until the graph executor (Macro C) and Studio land; deferred to Macro G alongside the Node-contract docs.
- Quality gates green on this branch: `composer validate --strict --no-check-publish`, `composer format:test`, `composer analyse`, `composer test` (Unit 425/1567, Architecture 5/75, Contract 77/370), verified on Herd PHP 8.5.7.

## 2026-07-08 - Macro B (Graph & Definitions) — implementation complete

- All 12 tasks of the Macro B plan implemented on macro branch `task/v2b-graph-definition` via seven subtask PRs: B-PR1 #47 (graph VOs + structural/semantic validation), B-PR2 #48 (canonical JSON schema v1 + content checksum), B-PR3 #49 (versioned `flow_definitions` repository), B-PR4 #50 (optional HMAC signing, verify-on-load), B-PR5 #51 (export/import commands + ModelsGenerator Flow-v2 importer), B-PR6 #52 (v1-to-graph compilation + `persist_registered` hardening), all MERGED; B-PR7 (this task, Tasks 11-12: run version pinning + macro closure) completed on branch `task/v2b-07-run-version-pinning`, not yet opened as a PR.
- Delivered: `Padosoft\LaravelFlow\Graph\` namespace — `GraphNode`/`Connection` structural VOs, `GraphDefinition` (structural invariants + precomputed Kahn topological order), `GraphValidator` (semantic validation against `NodeRegistry`: node types, port compatibility, anti-fan-in, required-input satisfaction), `GraphSerializer` (canonical schema v1 envelope + order-independent SHA-256 checksum), `StoredDefinition`, `DefinitionSigner` (opt-in HMAC signing), `GraphTransfer` (export/importDraft with a provenance envelope), `Flow2Importer` (structural-only ModelsGenerator Flow-v2 import). `Contracts\DefinitionRepository` + `EloquentDefinitionRepository` (`@internal`) add a versioned draft/published/archived lifecycle over the additive `flow_definitions` table, with lock-based concurrency-safe publish/archive and atomic checksum-dedupe (`createDraftIfChanged`). `flow:export` / `flow:import` (`@internal` Artisan commands) round-trip graphs as JSON. `FlowDefinition::toGraphDefinition()` compiles v1 fluent definitions to a graph (`legacy.step` nodes); `laravel-flow.definitions.persist_registered` (default off) persists a deduped versioned draft on `registerDefinition()`; compiled legacy drafts are blocked from `publish()` until Macro C's legacy-node execution lands.
- Task 11 (B-PR7): additive migration `2026_07_08_000006_add_definition_version_to_laravel_flow_runs` adds nullable `flow_runs.definition_version` + `definition_checksum`. `FlowEngine` records, per definition name, the `flow_definitions` version matched-or-produced by `persist_registered` during registration, and pins new runs of that definition to it at creation. `flow:replay` gained a checksum-aware drift check for pinned runs — a byte-identical current graph never warns; a changed one warns naming the pinned version — while unpinned/legacy runs (`definition_checksum` null) keep the original step-name/handler prefix check unchanged. Version-exact/graph-exact replay **re-execution** (actually running the pinned graph instead of the currently registered one) is explicitly deferred to Macro C.
- Task 12 (B-PR7): audited `GraphApiContractTest` against the full Macro B `@api` surface (`GraphNode`, `Connection`, `GraphDefinition`, `GraphValidator`, `InvalidGraphException`, `GraphSerializer`, `DefinitionRepository`, `StoredDefinition`, `DefinitionSigner`, `DefinitionSignatureException`, `DefinitionLifecycleException`, `DefinitionNotFoundException`, `GraphTransfer`, `Flow2Importer`, plus the `FlowDefinition::toGraphDefinition` and `createDraftIfChanged` pins). Class-level annotation pins and most lifecycle-method pins were already complete from B-PR1-6; closed the remaining method/property gaps (`GraphValidator::validate`, `GraphDefinition::node`/`nodeIds`, and the `GraphNode`/`Connection`/`StoredDefinition` readonly-property surfaces). `NodeAnnotationSweepTest` already sweeps every class under `src/Graph` (path-tolerant since Macro A) and needed no change. `docs/UPGRADE.md` gained a Graph/Definitions `@api` bullet mirroring the Node-namespace one (same pre-v2 stability note).
- Quality trail on this branch: `composer validate --strict --no-check-publish` clean; `composer format:test` (Pint) clean; `composer analyse` (PHPStan) no errors; `composer test` => Unit 449/1641, Architecture 5/75, Contract 83/394 — verified on Herd PHP 8.5.7.
- Deferred to Macro C: legacy-node execution/resolution strategy (carried over from the Macro A deferral), version-exact/graph-exact replay re-execution (from this PR), and fan-in merge nodes (`GraphValidator` currently rejects an input port wired from multiple sources; Macro C ships an explicit merge-node primitive instead). docs-site coverage for the whole Graph/Definitions surface stays deferred to Macro G alongside the Node-contract docs (unchanged from B-PR5).
- Next: open B-PR7 as a subtask PR (`task/v2b-07-run-version-pinning` -> `task/v2b-graph-definition`), local Copilot review loop + CI; then the Macro B PR (`task/v2b-graph-definition` -> `main`) summarizing all seven subtask PRs and the quality trail, Copilot review via GraphQL fallback, CI + review loop, and the Macro Gate G3 checklist — including authoring the Macro C detailed plan (legacy-node resolution, version-exact replay execution, fan-in merge nodes) — before Macro C starts.

## 2026-07-08 - Automode resume (session 3): B-PR7 local review loop

- Session resumed cold on `task/v2b-07-run-version-pinning` (clean tree, head `9244dde`). Verified local gates green (Unit 449/1641, Architecture 5/75, Contract 83/394) before running the mandatory local Copilot CLI review loop (`local-copilot-review` skill) on the full diff vs base branch `task/v2b-graph-definition` (NOT `origin/main` — this is a subtask branch whose PR targets the macro branch).
- Copilot verdict: one must-fix (race condition in `FlowEngine::persistRegisteredDefinitionIfEnabled()` — the unlocked `latest()` fallback after a `createDraftIfChanged()` dedupe-skip could pin a run to a version/checksum that doesn't match what was actually registered, if another draft lands in the unlocked window), one test-coverage gap for it, one low-priority Octane statefulness note (no action, pre-existing pattern). Fixed: recompute checksum locally and verify it matches `latest()`'s result before trusting it as a pin, else leave the run unpinned; added `RunVersionPinningTest::test_a_latest_version_that_does_not_match_the_registered_graph_leaves_the_run_unpinned` using a fake `DefinitionRepository` to deterministically simulate the window (real concurrency isn't reproducible on the shared-connection sqlite test DB, same limitation already documented for other locks in this repo). Lesson recorded in `docs/LESSON.md`.
- Local gates re-verified green after the fix: Unit 450/1644, Architecture 5/75, Contract 83/394. Re-running the local Copilot review loop on the updated diff next, then push + open B-PR7 against `task/v2b-graph-definition`.
- Automode: continuing per user instruction to resume through 100% roadmap completion across all involved packages (laravel-flow, laravel-flow-connect, laravel-flow-ai, laravel-flow-admin/dashboard). Task list tracks the macro-by-macro plan (B-PR7 -> Macro B merge -> Macro C plan+execute -> D -> F -> E -> G).
- PR #53 (B-PR7) review loop: round 1 (checksum race, nullable `%d` format, stale pin on repeated registration, migration `->after()` portability) fixed and pushed; CI green. Round 2 Copilot/Codex findings (GraphApiContractTest's `hasProperty()`-only assertions didn't guard visibility/readonly; migration `up()`/`down()` gated both columns on one column's presence instead of independently) fixed with regression tests; all 8 review threads resolved via GraphQL; CI green again on latest head (`5aaafda`). Final Copilot re-review requested, awaiting result before merge.
- Macro C detailed plan authored by a background research agent (`docs/superpowers/plans/2026-07-08-macro-c-graph-executor.md`, 555 lines, grounded in the actual current codebase via 3 code-reading sub-agents) and **deliberately NOT yet committed** — it lives only on local disk for now to keep it out of the in-flight Macro B PR's diff (it was accidentally swept into a `git add -A` twice and reverted both times; the file is in `.git/info/exclude` locally as a guard). It will be committed once Macro B merges to `main`, per the master plan's own "plan authored at the preceding Macro Gate" rule.
  - **User-directed policy shift** (their words, translated): laravel-flow isn't in production anywhere except one project the user controls the upgrade timing of; this is a major release; they want the most powerful/evolved architecture even at the cost of more work, not the safest option. This relaxes the earlier "v1 flow_steps table is sacrosanct" default.
  - **Chosen design**: ONE unified `flow_run_nodes` table + `Contracts\RunNodeRepository` (`@api`), written by BOTH the v1 linear `FlowEngine` and the new Macro C graph executor; `flow_steps` retired via an idempotent data migration (C-PR1, grown to 3 tasks: state machines, unified schema, v1 rewire + retirement with a golden before/after `StepSummary`-projection test proving v1 observable behavior is unchanged). Rationale: Macro B already compiles a v1 step into a `legacy.step` graph node, so v1 execution is already conceptually a degenerate graph run — one per-node table is the natural data model. Pays off across Macro D (one progress-snapshot shape), Macro E (one console row type), Macro G (a clean "steps became nodes" migration story instead of two step-shaped tables forever).
  - **Deliberately scoped OUT of Macro C**: actually routing v1's fluent-API *execution* through the graph executor too (unifying the engines, not just their persistence) — flagged as genuinely superior long-term but too much behavioral risk (compensation ordering, approval-resume) to add to the already-hardest-correctness macro. Noted in the plan as a natural future macro.
  - **Master-plan Global Constraints update drafted here, applied 2026-07-09** (see that section below): v1's *public* fluent API and `@api` surface stay backward-compatible as always; v1's *internal* persistence schema/`@internal` implementation is free to evolve in this major release given the single-consumer, self-controlled-upgrade context above.

## 2026-07-09 - Macro B MERGED to main; Macro Gate G3 closed; Macro C starting

- B-PR7 (#53) merged into `task/v2b-graph-definition` after 4 local-review + PR-review rounds (checksum race, stale pin, migration portability, then contract-test rigor + migration partial-state handling), all fixed with regression tests, final round `NO_FINDINGS`.
- Macro B PR (#54, `task/v2b-graph-definition` -> `main`) opened summarizing all 7 subtask PRs; went through 4 review rounds on the whole-macro diff (GitHub Copilot Code Review — this repo's mandatory PR-level reviewer — on every round; the `chatgpt-codex-connector` app, also installed on this repo, additionally commented on round 1), each fixed: (1) nullable `InvalidGraphException`/`JsonException` handling gaps in `warnAboutPinnedDrift()` and `persistRegisteredDefinitionIfEnabled()` (the latter's `toGraphDefinition()` call was outside its try/catch — moved in), a duplicate docblock, (2) `DefinitionSignatureException` also needed catching in `persistRegisteredDefinitionIfEnabled()`, (3) two style suggestions (drop docblock-only `use` imports in `DefinitionSigner`/`GraphValidator`) were tried and reverted — the project's own `vendor/bin/pint` (mandatory hard gate) auto-reverts that exact change back to imported-short-name docblocks on every run, so declined with rationale on the PR instead of fighting the formatter indefinitely. Merged as commit `df25f80`.
- **Macro B verified on `main`**: `composer quality` green — Unit 458/1663, Architecture 5/75, Contract 83/426 (Herd PHP 8.5.7). Acceptance criteria met: v1 regression suite untouched and green; graphs from JSON and from fluent-API compilation both persist/publish/export/re-import; run rows carry `definition_version`/`definition_checksum`.
- Macro C detailed plan (`docs/superpowers/plans/2026-07-08-macro-c-graph-executor.md`, 555 lines) and the master-plan Global Constraints update (relaxed v1-internal-persistence stance + the `flow_run_nodes` unification decision) land together on `task/macro-b-closure-macro-c-plan` -> `main`, closing Macro Gate G3.
- Next: Macro C branch `task/v2c-graph-executor` off `main`; ten subtask PRs (C-PR1..C-PR10) per the detailed plan, starting with C-PR1 (state machines + unified `flow_run_nodes` persistence, v1 engine rewired, `flow_steps` retired).
