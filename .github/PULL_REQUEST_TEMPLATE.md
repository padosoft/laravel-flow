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
- [ ] Pint clean (`vendor/bin/pint --test`)
- [ ] PHPStan clean (`vendor/bin/phpstan analyse --no-progress`)
- [ ] PHPUnit Unit suite (`vendor/bin/phpunit --testsuite Unit`)
- [ ] PHPUnit Architecture suite (`vendor/bin/phpunit --testsuite Architecture`)
- [ ] Companion app PHPUnit plus Vitest/Vite/Playwright green, if dashboard code/UI changed

## README impact

- [ ] `Features at a glance` is still accurate
- [ ] `Comparison vs alternatives` is updated for new or materially improved features
- [ ] Competitor claims were researched where uncertain

## Architecture impact

Briefly state package-core coupling, standalone-agnostic impact, and BC/API impact.

## Security impact

Briefly state secret/redaction/auth/webhook/approval-token impact, or `None`.

## Risk

Low/Medium/High plus mitigation.

## Rollback plan

How to revert safely if needed.
