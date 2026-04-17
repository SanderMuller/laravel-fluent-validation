---
name: pre-release
description: "Pre-push / pre-release checklist. Runs Rector, Pint, full test suite, PHPStan, and both benchmark harnesses (benchmark.php + --group=benchmark) to catch regressions before pushing or drafting release notes. Activate before: pushing to remote, tagging a release, writing release notes, or when user mentions: pre-release, pre-push, release checklist, ship, cut release, release notes."
---

# Pre-Release Checklist

Run this full gauntlet before pushing changes that may be tagged as a release or before drafting release notes. It catches regressions the two-tier `backend-quality` skill skips — namely Rector drift and performance regressions in both validation hot paths and DB-batching paths.

## When to Use This Skill

Activate when:
- About to push commits that will land in a release
- About to tag a release (`gh release create`)
- About to write or update release notes
- User says "ship it", "cut a release", "pre-push", "release checklist"
- A feature/fix is fully implemented and quality-gated

Do NOT use mid-development — this is a completion-level skill.

## Workflow

Run the checks **in this order**. Each check must pass before moving to the next. Fix issues as they surface; do not batch.

Always append `|| true` to verification commands so output is captured even on failure (per repo `CLAUDE.md` rule). Pass/fail is determined from the captured output, not the exit status alone.

### 1. Rector

```bash
vendor/bin/rector process || true
```

Must report **0 files changed**. If Rector modifies files, review the diff, commit the changes, and re-run until clean.

### 2. Pint

```bash
vendor/bin/pint --dirty --format agent || true
```

Must be clean. Re-run after Rector — Rector fixes can introduce style drift.

### 3. Full Test Suite

```bash
vendor/bin/pest || true
```

Must show 0 failures. Includes the parity suite (`FastCheckParityTest`) which guards Laravel behavioral equivalence. Note: `benchmark`-group tests are excluded from the default run — they are covered in step 5b below.

### 4. PHPStan

```bash
vendor/bin/phpstan analyse --memory-limit=2G || true
```

Must show 0 errors. Fix real issues — do not pad the baseline. See `backend-quality` skill for baseline rules.

### 5. Benchmarks — Regression Detection

This package has **two** benchmark harnesses. Both protect different surfaces and both must be run.

#### 5a. `benchmark.php` — validation hot-path scenarios

`benchmark.php --ci` only emits a `Δ vs base` column when `benchmark-snapshot.json` exists in the working tree. Without it, you get absolute numbers only — not a regression signal. To get a delta locally, mirror `.github/workflows/benchmark.yml`:

```bash
# 1. Capture baseline from the comparison ref (usually the last release tag or main)
BASE_REF="$(gh release list --limit 1 --json tagName -q '.[0].tagName')"  # or 'main'
git stash push -u -m 'pre-release-baseline' || true
git checkout "$BASE_REF"
composer install --no-interaction --prefer-dist --no-progress || true
php benchmark.php --snapshot || true           # writes benchmark-snapshot.json
cp benchmark-snapshot.json /tmp/benchmark-snapshot.json

# 2. Return to the working tree and run --ci against the saved snapshot
git checkout -
composer install --no-interaction --prefer-dist --no-progress || true
cp /tmp/benchmark-snapshot.json benchmark-snapshot.json
git stash pop || true
php benchmark.php --ci || true
php benchmark.php --ci || true                  # run at least twice — single runs have variance
```

If you cannot prepare a baseline (e.g. detached state, dirty checkout), run `php benchmark.php --ci || true` for absolute numbers and compare them against the last release's benchmark table in `gh release view <tag>` — but note the comparison is visual, not automated.

#### 5b. `vendor/bin/pest --group=benchmark` — DB batching + slow-path scenarios

```bash
vendor/bin/pest --group=benchmark || true
```

These tests (in `tests/ImportBenchTest.php`, `tests/SlowPathBenchTest.php`, `tests/RuleSetTest.php`) measure DB query-amplification paths for wildcard `exists`/`unique` rules and slow-path fallbacks. A release can pass `benchmark.php` while silently regressing these.

#### Regression criteria

Flag any of:
- A `benchmark.php` scenario's optimized time increased by **>10%** vs the baseline snapshot (or vs the last release's table, if no local snapshot)
- The speedup multiplier decreased by more than one notch (e.g. ~60x → ~45x)
- A `--group=benchmark` test's reported timing increased noticeably vs prior runs in the conversation or last release
- Any scenario switched from a fast-check path to a slower path unintentionally

If any regression is detected:
1. Identify the commit/change that introduced it (`git log -p` on hot-path files: `FastCheckCompiler`, `OptimizedValidator`, `RuleSet`, `WildcardExpander`, `HasFluentRules`, DB batching code)
2. Fix or revert
3. Re-run the affected harness to confirm recovery
4. Re-run tests (fix may change semantics)

Run `benchmark.php --ci` **at least twice** — single runs have variance. If the two runs disagree on regression, run a third.

## Quick Reference

| Step               | Command                                                          | Pass criteria                           |
|--------------------|------------------------------------------------------------------|-----------------------------------------|
| 1. Rector          | `vendor/bin/rector process \|\| true`                            | 0 files changed                         |
| 2. Pint            | `vendor/bin/pint --dirty --format agent \|\| true`               | clean                                   |
| 3. Tests           | `vendor/bin/pest \|\| true`                                      | 0 failures                              |
| 4. PHPStan         | `vendor/bin/phpstan analyse --memory-limit=2G \|\| true`         | 0 errors                                |
| 5a. Hot-path bench | snapshot baseline → `php benchmark.php --ci \|\| true` (2+ runs) | no >10% regression / speedup-notch drop |
| 5b. DB-batch bench | `vendor/bin/pest --group=benchmark \|\| true`                    | no timing regression vs last release    |

## Release Notes

Only draft release notes **after** all steps pass. Include the final benchmark table from the last run in the release body (CI normally auto-appends it between `<!-- benchmark-start -->` / `<!-- benchmark-end -->` markers — verify this actually happened via `gh release view <tag>`).

For release notes that claim a performance improvement or regression fix, cite the before/after benchmark numbers explicitly.

## Important

- Run every step, in order, even if nothing "perf-sensitive" looks changed. Seemingly unrelated refactors (e.g. closure shape, helper method dispatch) have historically introduced 20-40% regressions in the hot path.
- Do not push if any step fails. Fix, then restart the checklist from step 1 — earlier steps may re-break after a later fix.
- Step 5a and 5b are complementary, not redundant: 5a covers validation closure performance, 5b covers DB query amplification. Skipping either leaves a real blind spot.
