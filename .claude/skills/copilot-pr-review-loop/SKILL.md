---
name: copilot-pr-review-loop
description: After EVERY commit-push-PR cycle, the agent MUST loop on Copilot review + CI status until ALL Copilot comments resolved AND ALL CI checks green. NEVER stop after a single push. Trigger when opening a PR with `gh pr create`, after `git push` to a PR branch, or when user asks to "fix PR" / "address review" / "make CI green". Applies to ALL repos lopadova/* and padosoft/*. The loop is mandatory for current and future sessions and for any developer working on this codebase.
---

# Copilot PR Review + CI Loop — MANDATORY

## Rule

**NEVER stop a subtask after a single commit-push.** After every push, the agent
**MUST** loop on the following sequence until both conditions are satisfied:

1. Copilot review has **0 outstanding comments** (all addressed)
2. CI has **0 failing checks** (all green or expected-skipped)

## The 9-step flow (canonical, applies to EVERY PR on EVERY repo)

```
┌──────────────────────────────────────────────────────────────────┐
│ 1. fine task — implementation complete                            │
│ 2. test tutti verdi in locale                                     │
│    (phpunit + vitest + playwright + architecture)                 │
│ 3. apri PR with --reviewer copilot   ← MANDATORY FLAG             │
│ 4. attendi CI GitHub verde   (60-180s)                            │
│ 5. attendi Copilot review commenti  (additional 2-15 min)         │
│ 6. leggi commenti (gh pr view N --comments + inline) e fix        │
│ 7. ri-attendi CI tutta verde (after fix push)                     │
│ 8. (se Copilot ri-review) GOTO step 5                             │
│ 9. merge solo dopo:                                               │
│    - Copilot reviewDecision is APPROVED OR no must-fix outstanding│
│    - All CI checks status COMPLETED + conclusion SUCCESS          │
│      (or SKIPPED with explicit reason)                            │
└──────────────────────────────────────────────────────────────────┘
```

**KEY POINT (2026-04-29 reinforcement):** Step 3's `--reviewer copilot` flag and step 5's wait-for-Copilot-review are **NOT optional**. CI green alone is **not enough** — Copilot review (or explicit absence of must-fix comments) is the second gate. Skipping step 5 ("CI green, merge now") is a protocol violation, even on docs-only PRs.

## The legacy loop (kept for fix-iteration phase only)

When a PR has been opened and the FIRST review/CI cycle has surfaced issues:

```
┌─────────────────────────────────────────────────────────┐
│ A. push fix commit                                       │
│ B. wait 60-180s for Copilot re-review + CI to re-run     │
│ C. read PR review comments  (gh pr view N --comments)    │
│ D. read inline review comments (gh api .../comments)     │
│ E. read CI status            (gh pr checks N)            │
│ F. for each failing CI: read failed log                  │
│ G. fix all issues + run local test gate                  │
│ H. commit + push                                         │
│ I. GOTO step B                                           │
│                                                           │
│ EXIT only when:                                          │
│   - Copilot reviewDecision is APPROVED or no outstanding │
│   - All checks status COMPLETED + conclusion SUCCESS     │
└─────────────────────────────────────────────────────────┘
```

## Why this exists

Failure mode this rule prevents: "Claude pushes a commit, sees CI red,
sends a status report to user, stops working." This wastes a CI cycle
and hands a half-broken state to the user. The user has explicitly
said this is unacceptable: when CI is red, the agent must fix the
failure rather than reporting status and stopping. The Copilot review
gate is a separate, non-bypassable check that runs in parallel with
CI; both must converge before merge.

## Scope

Applies to **every PR** opened on:
- `lopadova/AskMyDocs`
- `padosoft/askmydocs-pro`
- `padosoft/laravel-ai-regolo`
- `padosoft/laravel-flow`
- `padosoft/eval-harness`
- `padosoft/laravel-pii-redactor`
- `padosoft/regolo-php-client` (when created)
- Any future Padosoft/Lopadova repo

Applies to **every developer** (Lorenzo, future Padosoft team members,
any AI agent).

## Exact commands per phase

### Phase A — Open PR
```bash
gh pr create \
  --title "feat(...): ..." \
  --base <base-branch> \
  --head <head-branch> \
  --body-file .github/PULL_REQUEST_TEMPLATE.md \
  --reviewer copilot
```

Use the correct base branch for the repository workflow. In repos with macro
branches, subtask PRs target the macro branch and macro PRs target `main`.

Note: `--reviewer copilot` may fail with "could not resolve user". In
that case, the repo must have Copilot Code Review enabled at:
`Settings → General → Pull Requests → Allow GitHub Copilot to review`.
Ask the user to enable it once per repo (one-time manual setup).

### Phase A.1 — Copilot reviewer fallback

If `gh pr create --reviewer copilot` or `gh pr edit <PR> --add-reviewer @copilot`
opens/updates the PR but fails to request Copilot because `copilot` does not
resolve, or because GitHub CLI tries to read project items and the token lacks
`read:project`, request the Copilot bot directly with GraphQL.

Bash / Linux / macOS:

```bash
pr_node_id="$(gh pr view <PR> --json id --jq .id)"

query='
mutation RequestReviewsByLogin($pullRequestId: ID!, $botLogins: [String!], $union: Boolean!) {
  requestReviewsByLogin(input: {pullRequestId: $pullRequestId, botLogins: $botLogins, union: $union}) {
    clientMutationId
  }
}
'

gh api graphql \
  -f query="$query" \
  -F pullRequestId="$pr_node_id" \
  -F botLogins[]='copilot-pull-request-reviewer[bot]' \
  -F union=true

gh api repos/<owner>/<repo>/pulls/<PR>/requested_reviewers
```

PowerShell:

```powershell
$prNodeId = gh pr view <PR> --json id --jq .id

$query = @'
mutation RequestReviewsByLogin($pullRequestId: ID!, $botLogins: [String!], $union: Boolean!) {
  requestReviewsByLogin(input: {pullRequestId: $pullRequestId, botLogins: $botLogins, union: $union}) {
    clientMutationId
  }
}
'@

gh api graphql `
  -f query="$query" `
  -F pullRequestId="$prNodeId" `
  -F botLogins[]='copilot-pull-request-reviewer[bot]' `
  -F union=true

gh api repos/<owner>/<repo>/pulls/<PR>/requested_reviewers
```

The verification call must show pending reviewer `Copilot`. The REST endpoint
with `reviewers[]=copilot` is not equivalent; it can return success without
creating a visible Copilot Code Review request.

### Phase B — Read review (after 60-180s wait)
```bash
# overview
gh pr view <PR> --json state,reviewDecision,mergeable,statusCheckRollup

# top-level comments
gh pr view <PR> --comments

# inline review comments (specific lines)
gh api repos/<owner>/<repo>/pulls/<PR>/comments --jq '.[] | {body, path, line}'
```

If `gh pr view <PR> --comments` fails because the token lacks `read:project`,
use API endpoints that do not query project items:

```bash
# PR review summaries
gh api repos/<owner>/<repo>/pulls/<PR>/reviews \
  --jq '.[] | {user:.user.login,state,commit_id,body,submitted_at}'

# top-level PR conversation comments
gh api repos/<owner>/<repo>/issues/<PR>/comments \
  --jq '.[] | {user:.user.login,body,created_at}'

# inline review comments
gh api repos/<owner>/<repo>/pulls/<PR>/comments \
  --jq '.[] | {user:.user.login,path,line,body,commit_id}'
```

For thread state, use GraphQL so outdated/resolved status is explicit:

```bash
query='
query($owner:String!, $repo:String!, $number:Int!) {
  repository(owner:$owner, name:$repo) {
    pullRequest(number:$number) {
      reviewThreads(first:100) {
        nodes {
          id
          isResolved
          isOutdated
          comments(first:10) {
            nodes { author { login } path line outdated body }
          }
        }
      }
    }
  }
}
'

gh api graphql \
  -f query="$query" \
  -f owner='<owner>' \
  -f repo='<repo>' \
  -F number=<PR>
```

### Phase C — Read CI failures
```bash
# list runs for branch
gh run list --branch <branch> --limit 3 --json databaseId,status,conclusion,name

# read failed-job log
gh run view <run-id> --log-failed | head -200
```

### Phase D — Fix locally + test gate

Run the gates the **current repo** actually has. Each repo opts into
the subset that applies; do not run a step the repo does not ship.

```bash
# Always (PHP repos):
vendor/bin/phpunit --no-coverage              # unit + feature suite
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test

# When the repo ships a frontend (presence of frontend/ or package.json):
cd frontend && npm test                       # vitest
npm run e2e                                   # playwright
cd ..

# When the repo defines a dedicated Architecture testsuite in phpunit.xml:
vendor/bin/phpunit --testsuite Architecture   # R30+R31+R32+R34+R35
```

The AskMyDocs main repo runs every step above. Standalone Padosoft
packages (laravel-ai-regolo, eval-harness, laravel-flow,
laravel-pii-redactor) run only the always-on row plus their own
unit suite.

### Phase E — Commit + push
```bash
git add <changed files>
git commit -m "fix(...): address Copilot review on PR #<N> + CI green"
git push origin <branch>
```

Then GOTO Phase B. Never stop after a single push.

## What counts as "Copilot must-fix"

- Bug (off-by-one, null deref, race condition)
- Security (XSS, SQLi, auth bypass, secret leak)
- R-rule violation (R30 cross-tenant, R32 memory privacy, R3 N+1, etc.)
- Test gap (untested branch, unhandled error path)

These MUST be fixed before merge.

## What counts as "should-fix"

- Code style, naming, idiom
- Documentation quality
- Minor refactoring

These SHOULD be fixed unless there's an explicit reason not to. If
declining, reply on the comment with a brief rationale.

## What counts as "discuss"

- Ambiguous suggestions where Copilot may have misunderstood context
- False positives
- Intentional design decisions

Reply explaining; mark resolved when consensus reached.

## Anti-patterns (NEVER DO)

- ❌ Push a commit, see CI red, stop and report to user
- ❌ Skip Copilot review because "it's just a small fix"
- ❌ Mark Copilot comment "resolved" without actually fixing
- ❌ Merge with even 1 outstanding Copilot must-fix comment
- ❌ Merge with CI red (any check failure)
- ❌ Run only phpunit and skip vitest / playwright / architecture
- ❌ Wait less than 60s after push before checking CI (CI may not have started)

## Operational tip — CI iteration time budget

Each CI run is ~2-5 minutes. Plan accordingly:
- Push 1: typical 5-10 Copilot comments + maybe CI red
- Push 2 (after fixes): 1-3 residual comments + CI usually green
- Push 3+: rare; if you reach push 4 without convergence, the issue is
  deeper than a quick fix — ask for human review.

## Reference

- `CLAUDE.md` R36 in repos that ship one (e.g.
  [`lopadova/AskMyDocs/CLAUDE.md`](https://github.com/lopadova/AskMyDocs/blob/main/CLAUDE.md))
  — the canonical statement of this rule and its scope
- The skill's own header `description` field — short trigger summary
  every Claude Code agent reads on session start

First-instance lessons and weekly status notes are tracked outside
the public skill so this file stays portable across public and
private repos.
