# Contributing

## AI Tooling (Laravel Boost)

This package uses [Laravel Boost](https://laravel.com/docs/boost) for AI-assisted development via Orchestra Testbench.

### Setup

```bash
composer install
vendor/bin/testbench boost:install
```

### After Updating Dependencies

```bash
vendor/bin/testbench boost:update
```

This regenerates AI guidelines, skills, and MCP server configuration for Claude Code, Codex, Cursor, and Copilot.
