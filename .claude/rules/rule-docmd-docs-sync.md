# Rule: docmd docs stay in sync

When package behavior changes, update `docs-site/` in the same change set.

- Add new pages to `docmd.config.json` navigation.
- Keep examples valid PHP and aligned with public `@api` classes.
- Do not use raw HTML, MDX, JSX, or `::: button`.
- Run `npm run check` and `npm run build` before pushing documentation changes.
