# Contributing

## AI Tooling

This package uses [Package Boost](https://github.com/sandermuller/package-boost) and [Laravel Boost](https://laravel.com/docs/boost) for AI-assisted development. Laravel Boost provides the MCP server via Orchestra Testbench; Package Boost bridges Boost to package development by syncing `.ai/` skills and guidelines into the directories each AI tool expects (`.claude/`, `.github/`, `CLAUDE.md`, `AGENTS.md`, `.mcp.json`).

### Setup

```bash
composer install
vendor/bin/testbench boost:install
```

### Authoring skills and guidelines

Edit sources under `.ai/`:

```
.ai/
├── guidelines/   # merged into CLAUDE.md, AGENTS.md, .github/copilot-instructions.md
└── skills/       # synced to .claude/skills/ and .github/skills/
```

### Sync after edits or dependency updates

```bash
composer sync-ai
```

Equivalent to `vendor/bin/testbench package-boost:sync`. Regenerates skills, guidelines, and `.mcp.json` for Claude Code, Codex, Cursor, and Copilot. Commit both `.ai/` sources and generated files.

Selective sync:

```bash
vendor/bin/testbench package-boost:sync --skills
vendor/bin/testbench package-boost:sync --guidelines
vendor/bin/testbench package-boost:sync --mcp
```
