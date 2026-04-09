---
name: backend-quality
description: "Runs backend code quality checks in two tiers: Pint + related tests (every change), PHPStan + full suite (completion only). Activate after making changes to PHP files, or when user mentions: phpstan, pint, code quality, static analysis, code style, run checks."
---

# Backend Code Quality

Run backend quality checks after making changes to PHP files. Which checks to run depends on where you are in the workflow — see the two tiers below.

## When to Use This Skill

Activate this skill when:
- PHP files have been created or modified
- Finalizing a feature, bug fix, or refactor that touched PHP code
- The user asks to run backend checks, PHPStan, Pint, or tests
- Before creating a PR with PHP changes

## Two Tiers of Checks

### Tier 1: During Development (after each change)

Run these checks every time you modify PHP files — they are fast:

**1. Pint (Code Style)**

```bash
vendor/bin/pint --dirty --format agent
```

Fix any formatting issues. Re-run until clean.

**2. Related Tests Only**

Run the minimum scope needed:

```bash
# Specific test file
vendor/bin/pest tests/RelevantTest.php

# Filter by test name
vendor/bin/pest --filter=testMethodName
```

All related tests must pass.

### Tier 2: At Completion (once, at the very end)

Run these checks **only when the feature, bug fix, or spec is fully implemented** — right before creating a PR or marking work as done. These are slow and should not be run mid-development.

**1. Pint** (re-run to be sure)

```bash
vendor/bin/pint --dirty --format agent
```

**2. PHPStan (Static Analysis)**

```bash
vendor/bin/phpstan analyse --memory-limit=2G
```

Must show 0 errors. Fix any issues found and re-run Pint after fixes.

**3. Full Test Suite**

```bash
vendor/bin/pest
```

Must show 0 failures. This catches cross-cutting regressions.

## Quick Reference

| Check | Command | When to run | Pass criteria |
|-------|---------|-------------|---------------|
| Code style | `vendor/bin/pint --dirty --format agent` | Every change | No changes made |
| Related tests | `vendor/bin/pest [--filter]` | Every change | 0 failures |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=2G` | Completion only | 0 errors |
| Full test suite | `vendor/bin/pest` | Completion only | 0 failures |
| Benchmarks | `vendor/bin/pest --group=benchmark` | Performance changes | No regression |

## Important

- Run Pint **before** PHPStan — style fixes can resolve some PHPStan issues.
- Run Pint **again after** PHPStan fixes — PHPStan fixes may introduce style issues.
- **Do NOT run PHPStan or the full test suite mid-feature.** They are slow and waste time when the code is still in flux.
- When the user explicitly asks to run PHPStan or the full suite, always obey regardless of tier.

## Running Benchmarks

Benchmark tests are in the `benchmark` group and output timing data to stderr.

```bash
vendor/bin/pest --group=benchmark
```

Run benchmarks when:
- Performance-sensitive code changes (FastCheckCompiler, OptimizedValidator, RuleSet, WildcardExpander, HasFluentRules)
- Evaluating whether a refactor impacts validation speed
- Comparing before/after for a specific optimization

> If a Pest output formatting plugin (e.g., `nunomaduro/pao`) swallows the benchmark stderr output, pipe through `cat`: `vendor/bin/pest --group=benchmark 2>&1 | cat`

## Fixing PHPStan Errors

**Always fix the actual code.** Never suppress PHPStan errors by:

- Adding `@phpstan-ignore`, `@phpstan-ignore-line`, or `@phpstan-ignore-next-line` comments
- Adding entries to `phpstan-baseline.neon`
- Modifying `phpstan.neon` (e.g. `ignoreErrors`, `excludePaths`, lowering the level or otherwise reducing strictness)

**The only exception** is a confirmed upstream bug in a dependency or PHPStan itself that cannot be resolved in the project code. In that case, explain the upstream issue and ask the user for approval before adding any suppression.
