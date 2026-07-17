# E-PR6 core `@api` mutation seams — implementation plan

> Four additive `@api` seams the companion dashboard (`laravel-flow-admin`) needs to
> ship E-PR6 (working mutations). Each is a SEPARATE PR through the full DoD loop
> (local gates → local Copilot review → PR → Copilot + CI → merge). All four are
> **additive minor** changes (new public methods on existing `@api` classes) — none
> removes/renames a pinned member. Each PR must extend the `tests/Contract/` pin
> arrays AND add a "new in vX.Y" note to `docs/UPGRADE.md` in the same PR.

## Decisions taken (2026-07-17)
- **Seam 2 (cancel)**: map cancelled nodes to EXISTING states — `Pending → Skipped`,
  `Running`/`Paused` → `Failed` (both `canTransitionTo`-legal). Do NOT add a
  `NodeState::Cancelled` case (that mutates a pinned enum). Run → `Aborted`.
- **Seam 4 (redeliver)**: Option A — `Flow::redeliverWebhook(int)` →
  `FlowEngine::redeliverWebhook(int): bool` → new public `redeliver(int): bool` on
  the `@internal` `EloquentWebhookOutboxRepository` (CAS). No new contract interface.

## Key files
- Facade: `src/Facades/Flow.php` (`@method static` docblock lines ~18-29; forwards to `FlowEngine` singleton).
- Engine: `src/FlowEngine.php` (`@api`, non-final singleton).
- Approvals: `src/ApprovalTokenManager.php` (`@api`, final). `hashToken()` = sha256 hex (~:215).
- Run repo: `src/Persistence/EloquentRunRepository.php` (`@internal`) impl of `Contracts\ConditionalRunRepository` (`updateWhereStatus(runId, expectedStatus, attrs): ?FlowRunRecord`, `@api`). `UPDATABLE_COLUMNS` includes status/finished_at/duration_ms.
- Run-node: `Contracts\RunNodeRepository` (`@api`): `createOrUpdate`, `forRun`, `states(): array<string,NodeState>`. Impl `src/Persistence/EloquentRunNodeRepository.php`. NOTE: `node_type` is NOT NULL on upsert.
- Outbox: `src/Persistence/EloquentWebhookOutboxRepository.php` (`@internal`, no contract). `claimNextPending` selects `status='pending' AND attempts < max_attempts` (or lease-expired). Status consts pending/delivering/delivered/failed.
- Enums: `src/Executor/State/RunState.php` (`isTerminal`; `Aborted` reachable from Running+Paused), `src/Executor/State/NodeState.php`.
- Read model: `src/Dashboard/FlowDashboardReadModel.php` (`findRun`, `listWebhookOutbox`, `failedWebhookOutbox` — surface the outbox `int id`).
- Replay blueprint: `src/Console/ReplayFlowRunCommand.php` (`@internal`) — complete working logic to lift into the engine.
- Options: `src/FlowExecutionOptions.php` (`make(correlationId, idempotencyKey, replayedFromRunId)`).
- Contract tests: `tests/Contract/PublicApiContractTest.php` (`test_flow_engine_pins_documented_public_methods()` method-name array ~:113; ApprovalTokenManager pin ~:217). `tests/Contract/ExecutorApiContractTest.php` (pins NodeState/RunState case lists — do NOT change).

## Seam 1 — approve/reject by token HASH (MOST INVASIVE — do isolated)
Thread `$tokenHash` (not the plain token) through the private chain; the plain token is ONLY ever hashed at the `ApprovalTokenManager` boundary, used for nothing else.
- Private chain in `FlowEngine.php`: `resume(:365)/reject(:376)` → `decideApproval(:854)` → `approvalDecisionRecord(:870)`→`ATM::find(:873)`; `withApprovalDecisionLock(:1060)`→`approvalRunStateForToken(:1106)`; `decideApprovalWithLock(:891)`→`decideGraphApproval(:944)`→`consumeApprovalDecisionForPausedRun(:1363)`→`ATM::approveForRunStatus/rejectForRunStatus(:1374-1375)` + fallback `find(:1385)`.
- Add to `ApprovalTokenManager`: `findByHash(string $tokenHash)` (copy of `find()` minus the hashToken call, KEEP the expirePending side-effect), `consumeByHash(...)` (extract body of `consume()` below the hashToken line), `approveForRunStatusByHash`, `rejectForRunStatusByHash`.
- Add to `FlowEngine`: `resumeByHash(string $tokenHash, array $payload=[], array $actor=[]): FlowRun`, `rejectByHash(...)`. Change private-chain params `string $token`→`string $tokenHash`; sinks call the `*ByHash` methods. `resume()/reject()` guard the PLAIN token blank BEFORE hashing, then `decideApproval(hashToken(trim($token)), ...)`; `decideApproval` guards hash shape (non-empty; optionally 64-hex).
- Facade: `@method static FlowRun resumeByHash(...)`, `rejectByHash(...)`. Contract pin + UPGRADE.
- Tests: assert `resumeByHash(hashToken($plain))` ≡ `resume($plain)` for v1 linear, graph, reject+compensate, crash-window, chained gate, lock-loss.

## Seam 2 — cancel (`FlowEngine::cancel(string $runId, array $actor=[]): FlowRun`)
- `$store = storeForExecution(false)`; null → `FlowExecutionException`. `$record = store->runs()->find($runId)` (guard QueryException). Not found → exception.
- If `RunState::from($record->status)->isTerminal()` → return `flowRunFromRecord` unchanged (idempotent).
- In `persistAtomically`: CAS `conditionalRuns($store)->updateWhereStatus($runId, $record->status, ['status'=>Aborted, 'finished_at'=>now, 'duration_ms'=>…])`; if null re-read+return (lost race). For each non-terminal node row (`states()`): `runNodes()->createOrUpdate($runId,$nodeId,['status'=>Skipped|Failed,'finished_at'=>now,'node_type'=>$row->node_type])`.
- Facade `@method static FlowRun cancel(...)`. Contract pin + UPGRADE. (No `canCancelRun` on DashboardActionAuthorizer — host gates; admin already maps `cancel_run` in Support\Authorize.)

## Seam 3 — replay (`FlowEngine::replay(string $runId, ?FlowExecutionOptions $options=null): FlowRun`)
- Lift `ReplayFlowRunCommand::handle()` into the engine; reduce the command to a thin wrapper `$flow->replay($runId)`.
- Legacy path returns `FlowRun` directly (`execute($name,$input,options)`). Graph path: `runGraph(...)` returns `GraphRunResult` → re-read `store->runs()->find($result->runId)` → `flowRunFromRecord` to satisfy `: FlowRun`.
- `$options` null → synthesize `FlowExecutionOptions::make(correlationId: $original->correlation_id, replayedFromRunId: $original->id)`. If caller passes options, force `replayedFromRunId=$original->id`, keep their correlationId/idempotencyKey.
- Throw typed `FlowExecutionException` instead of console FAILURE for: persistence-off, not-found, non-terminal, non-array-input, unregistered-definition, stored-graph-load-failure. Log drift at warning (no console).
- Facade `@method static FlowRun replay(...)`. Contract pin (+ fix the stale comment ~:111 "Replay is exposed as flow:replay command rather than a FlowEngine method") + UPGRADE.

## Seam 4 — redeliver webhook (`Flow::redeliverWebhook(int): bool`)
- `EloquentWebhookOutboxRepository::redeliver(int $outboxId): bool` — CAS `UPDATE ... SET status='pending', attempts=0, available_at=now, last_error=NULL, failed_at=NULL WHERE id=? AND status='failed'`; return whether 1 row changed. Resetting `attempts=0` re-opens the `attempts<max_attempts` gate so `claimNextPending` re-picks it.
- `FlowEngine::redeliverWebhook(int $outboxId): bool` resolves the internal repo + guards ONLY persistence-enabled (throws `FlowExecutionException` when off, wraps `QueryException`); it is intentionally NOT gated on `webhook.enabled` (that flag governs recording NEW rows, not redelivering an already-failed one). `Flow::redeliverWebhook` `@method`. Contract pin (`redeliverWebhook`) + UPGRADE. **Shipped in PR #93.**
- Tests: seed a `failed` row → redeliver → pending/attempts=0/available<=now/cleared → `flow:deliver-webhooks` claims+delivers; non-failed → false; missing id → false.

## Then: admin E-PR6 (separate, in laravel-flow-admin, after the 4 core PRs merge + core dev-main updates)
Controllers wrapping each seam in `Support\Authorize::action` (map already has approve/reject/cancel_run/replay_run/retry_webhook), routes (`POST /approvals/{tokenHash}/approve|reject`, `POST /runs/{id}/cancel|replay`, `POST /outbox/{id}/redeliver`), Feature tests (each 403-by-default + succeeds with allowing authorizer), Playwright, and remove the inert v1 buttons. Approvals identify by **token hash**.
