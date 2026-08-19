# Documentation

Fluent validation rule builders for Laravel. For a quick overview and installation, see the [main README](https://github.com/SanderMuller/laravel-fluent-validation/blob/main/README.md).

## Getting started

- [Why this package?](01-why-this-package.md) — DX, type safety, structure, performance, and how it compares to Laravel's `Rule` class
- [Installation](02-installation.md)
- [Basic usage](03-basic-usage.md) — form requests, typing `rules()`, the `schema()` builder, other contexts
- [Error messages](04-error-messages.md) — labels, per-rule messages
- [Array validation](05-array-validation.md) — `each()`, `children()`, nesting

## Digging deeper

- [Extending parent rules](06-extending-rules.md) — child form requests, `modifyEach`, `modifyChildren`, returning a `RuleSet`
- [Livewire](07-livewire.md) — `HasFluentValidation` trait, Filament workaround
- [Performance](08-performance.md) — O(n) wildcards, pre-evaluation, fast-check closures, batched DB, benchmarks
- [RuleSet](09-ruleset.md) — build, compose, inspect, validate, escape hatches, method reference
- [Testing](10-testing.md) — `FluentRulesTester`, Pest expectations
- [Rule reference](11-rule-reference.md) — all types, modifiers, conditionals, macros

## Migration and tooling

- [Migrating to fluent validation](12-migration.md) — incremental migration and the automated [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector)
- [Static analysis with PHPStan](13-static-analysis.md) — opt-in [PHPStan rules package](https://github.com/sandermuller/laravel-fluent-validation-phpstan) that flags unbounded `each()` chains
- [Troubleshooting](14-troubleshooting.md) — common issues and solutions
