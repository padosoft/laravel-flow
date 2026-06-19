---
name: docmd-docs
description: Create and maintain the docmd documentation site for laravel-flow.
---

# docmd Docs

Use this skill when changing the `docs-site/` documentation.

1. Keep content Markdown-only. Do not use MDX, JSX, or raw HTML.
2. Use docmd containers for structured content: `callout`, `tabs`, `steps`, `collapsible`, `grids`, `grid`, and `card`.
3. Keep every page present in `docmd.config.json` navigation.
4. Run `npm run check` and `npm run build` after changes.
5. Verify `_site/index.html`, `_site/llms.txt`, `_site/sitemap.xml`, and `_site/.docmd-search/manifest.json` when semantic search is enabled.
