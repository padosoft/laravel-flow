---
name: local-copilot-review
description: BEFORE every push of a task/subtask branch, run a LOCAL GitHub Copilot CLI review loop on the FULL branch diff vs origin/main and iterate until zero actionable findings. Trigger before `git push`, before `gh pr create`, after completing a subtask implementation, or when the user asks for "local review" / "review locale" / "copilot locale". Complements (never replaces) copilot-pr-review-loop, which handles the PR-level review after push.
---

# Local Copilot CLI Review Loop — MANDATORY BEFORE PUSH

## Rule

No branch gets pushed until a **local** GitHub Copilot CLI review of the **full branch diff vs `origin/main`** returns **zero actionable findings**. This catches issues before they burn a CI + PR-review cycle.

Verified environment: GitHub Copilot CLI ≥ 1.0.68 on PATH (`copilot --version`). Flags used: `--autopilot`, `--yolo` (all permissions), `-s` (silent, response only), `-p` (non-interactive prompt).

## Process

### 1. Preconditions
- All local gates already green (`composer quality`; plus `npm run test` / `npm run build` / `npm run e2e` when JS/UI was touched).
- **Everything committed.** The review covers the branch diff, not the working tree; uncommitted work is invisible to it.

### 2. Generate the FULL branch diff (never partial)

```bash
git fetch origin main
branch="$(git branch --show-current)"
diff_file="$TEMP/copilot-review-${branch//\//-}.diff"
git diff origin/main...HEAD > "$diff_file"          # three-dot: branch changes since merge-base
git diff origin/main...HEAD --stat | tail -1        # sanity: size + files count
```

**Always pass the file path**, never inline the diff in the prompt (large diffs overflow the prompt; the file also guarantees Copilot sees the complete context, not just unstaged files).

### 3. Run the non-interactive review

```bash
copilot --autopilot --yolo -s -p "/review Review the FULL branch diff of branch '${branch}' vs origin/main for this Laravel 13 / PHP ^8.3 package. The complete unified diff is in the file: ${diff_file} — read that file first. Focus on: real bugs, security issues, race conditions, Laravel 13 / PHP 8.3-8.5 compatibility, missing/weak tests for new behavior, @api/@internal contract violations, secrets in code or logs. Reply ONLY with a numbered list of actionable findings in the form 'file:line — issue — suggested fix'. If nothing is actionable, reply exactly: NO_FINDINGS"
```

### 4. Triage findings (same taxonomy as the PR loop)
- **must-fix** (bug, security, race, test gap, contract violation): fix now.
- **should-fix** (style, naming, docs): fix unless there is a written reason not to; carry the rationale into the PR description.
- **false positive / intentional**: note the rationale; it goes in the PR description so the cloud Copilot review sees it too.

### 5. Iterate
After any fix: re-run local gates → commit → **regenerate the diff** (step 2) → re-run the review (step 3). The loop exits ONLY on `NO_FINDINGS` (or only explicitly-rationalized non-actionable notes).

### 6. Hand off
Push, then immediately enter the `copilot-pr-review-loop` skill for the PR-level loop (CI + cloud Copilot review). The local loop does not replace the PR loop — both gates are mandatory.

### 7. Learn
If a local finding reveals a recurring footgun, append it to `docs/LESSON.md` (dated `YYYY-MM-DD`), same as PR-review lessons.

## Anti-patterns (NEVER DO)
- ❌ Reviewing only uncommitted/staged files — the input is always the full branch diff vs `origin/main`.
- ❌ Pushing with unresolved must-fix local findings ("CI will catch it").
- ❌ Skipping because "docs-only change" — docs diffs are cheap to review; review them.
- ❌ Inlining a huge diff into `-p` instead of passing the file path.
- ❌ Treating local NO_FINDINGS as a substitute for the PR-level Copilot review.
