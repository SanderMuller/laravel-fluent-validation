# Contributing

## Documentation

The documentation site is published at
[sandermuller.github.io/laravel-fluent-validation](https://sandermuller.github.io/laravel-fluent-validation/).
Its sources live in [`docs/`](docs/README.md) — one Markdown file per
page, `NN-` prefixed so GitHub renders the folder in reading order. The
prefix is stripped at build time, so a page's site URL stays stable when
pages are reordered.

`docs/.vitepress/config.mts` holds the nav, sidebar, and the `pages`
list. Add a new page to that list, or its route will not resolve.
`docs/README.md` is the GitHub-facing folder index and is excluded from
the site; `docs/home.md` becomes the site home page. Keep both in sync
with the page list when you add or rename a page.

Build the site locally before you push:

```bash
cd docs
npm ci
npm run dev     # local preview
npm run build   # what CI runs
```

`.github/workflows/docs.yml` builds and deploys to GitHub Pages on every
push to `main` that touches `docs/`.

## AI Tooling

This package uses [boost-core](https://github.com/sandermuller/boost-core)
for AI-assisted development, via two dev dependencies:

- [`sandermuller/boost-skills`](https://github.com/sandermuller/boost-skills) — the shared skill + guideline catalog.
- [`sandermuller/package-boost-laravel`](https://github.com/sandermuller/package-boost-laravel) — the Laravel-package role engine (pulls `boost-core` + `package-boost-php`).

The engine syncs `.ai/` sources plus allowlisted vendor skills/guidelines
into the directories each AI tool expects (`.claude/`, `.github/`,
`.agents/`, `CLAUDE.md`, `AGENTS.md`). Configuration lives in
`.config/boost.php` (allowed vendors, agents, tags). There is no MCP
server — this package
does not depend on `laravel/boost`.

### Setup

```bash
composer install
```

`.config/boost.php` is committed, so no install step is needed. To
reconfigure agents/vendors interactively, run `vendor/bin/boost install`.

### Authoring skills and guidelines

Edit sources under `.ai/` — never edit the generated agent directories:

```
.ai/
├── guidelines/   # merged into CLAUDE.md, AGENTS.md, Copilot instructions
└── skills/       # synced to .claude/skills/, .github/skills/, .agents/skills/
```

Vendor skills/guidelines are enabled through `.config/boost.php`'s
`withAllowedVendors([...])` + `withTags([...])`. Inspect what resolves
and from where with `vendor/bin/boost where`.

> **Shipped product vs dev tooling.** `resources/boost/skills/` holds the
> `fluent-validation` skills this package *ships to its own consumers* — a
> separate axis from `.ai/` (what we consume while developing). Do not
> confuse the two; adoption tooling never touches `resources/boost/`.

### Sync after edits or dependency updates

```bash
composer sync-ai
```

Equivalent to `vendor/bin/boost sync`. Regenerates skills and guidelines
for Claude Code, Codex, and Copilot from `.ai/` + allowlisted vendors.

The generated agent directories (`.claude/skills/`, `.github/skills/`,
`.agents/skills/`, `.claude/commands/`, `.github/prompts/`) are
**gitignored** — they are regenerated, not committed. Commit only the
`.ai/` sources and `.config/boost.php`.

### Verify

```bash
vendor/bin/boost sync --check   # report drift without writing (non-zero exit on drift)
vendor/bin/boost validate       # validate boost.php against vendor schemas
vendor/bin/boost doctor         # diagnose config, allowlist, drift
```
