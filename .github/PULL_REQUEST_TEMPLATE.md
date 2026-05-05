## Work item

Branch or work item:

Plan reference (enterprise roadmap only; write `N/A` for community PRs):

## Summary

1-2 sentence description of the change.

## Changes

- Files or subsystems changed
- Public API/config/migration changes, if any
- Documentation changes, if any

## Test gate

- [ ] Composer validation (`composer validate --strict --no-check-publish`)
- [ ] Pint clean (`composer format:test`)
- [ ] PHPStan clean (`composer analyse`)
- [ ] PHPUnit Unit + Architecture suites (`composer test`)
- [ ] Companion dashboard app gates, run in that app/repo only: PHPUnit, Vitest/Vite/build, and Playwright; write `N/A` for package-only dashboard contracts
- [ ] GitHub Copilot Code Review requested by the author if they have permission; otherwise maintainer must request before merge; actionable comments addressed

## README impact

- [ ] `Features at a glance` is still accurate
- [ ] `Comparison vs alternatives` is updated for new or materially improved features
- [ ] Competitor claims were researched where uncertain

## Architecture impact

Briefly state package-core coupling, standalone-agnostic impact, and BC/API impact.

## v1.0 stability impact

- [ ] No `@api` class, public method signature, or public constant was removed or renamed without a major version bump
- [ ] No `@api` class accidentally accepts an `@internal` type in its public surface (would force the class itself to be internal)
- [ ] If new public methods/classes were added under `@api`, `tests/Contract/PublicApiContractTest.php` was updated to pin them
- [ ] `docs/UPGRADE.md` was updated if the change is observable to consumers

## Security impact

Briefly state secret/redaction/auth/webhook/approval-token impact, or `None`.

## Risk

Low/Medium/High plus mitigation.

## Rollback plan

How to revert safely if needed.
