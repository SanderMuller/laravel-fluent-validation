---
name: pre-release
description: "Pre-push / pre-release checklist. Runs Rector, Pint, full test suite, PHPStan, audits README + `.ai/` docs for staleness, and runs both benchmark harnesses (benchmark.php + --group=benchmark). Activate before: pushing to remote, tagging a release, writing release notes, or when user mentions: pre-release, pre-push, release checklist, ship, cut release, release notes."
---

# Pre-Release Checklist

Run this full gauntlet before pushing commits that may be tagged as a release or before drafting release notes. It catches regressions the two-tier `backend-quality` skill skips — Rector drift, stale docs shipped to downstream projects, and performance regressions in both validation hot paths and DB-batching paths.

## When to Use This Skill

Activate when:
- About to push commits that will land in a release
- About to tag a release (`gh release create`)
- About to write or update release notes
- User says "ship it", "cut a release", "pre-push", "release checklist"
- A feature/fix is fully implemented and quality-gated

Do NOT use mid-development — this is a completion-level skill.

## Workflow

Run the checks **in this order**. Each must pass before moving to the next. Fix issues as they surface; do not batch.

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

Must show 0 failures. Includes the parity suite (`FastCheckParityTest`) which guards Laravel behavioral equivalence. `benchmark`-group tests are excluded from the default run — they are covered in step 6b below.

### 4. PHPStan

```bash
vendor/bin/phpstan analyse --memory-limit=2G || true
```

Must show 0 errors. Fix real issues — do not pad the baseline. See `backend-quality` skill for baseline rules.

### 5. Documentation freshness audit

Release-worthy features change user-visible behavior, so `README.md` and the `.ai/` files we ship to downstream projects (via `package-boost:sync`) can drift silently. Every release must audit both.

**Rule:** add or edit docs only where they reflect a real change. Do not bloat the README or skills. Delete stale content aggressively.

#### 5a. README

Scan `README.md` against the commits in this release (`git log <last-tag>..HEAD`). Update these sections when relevant:

- **Benchmark table** (roughly line ~450) — if any scenario's numbers, speedup, or `Optimizations` label changed.
- **Fast-check closures section** — if the list of supported rules changed (new rule family, new operator, new field-ref form).
- **Scenario narratives** — if a scenario's comments (`// field ref → Laravel` vs `// → fast-checked`) no longer match reality.
- **"When this won't help" / limitations** — every new fast-checkable rule reduces this list; keep it honest.
- **Public API signatures** — if a method gained a parameter, a new public method was added, or behavior changed.

If unsure whether a change warrants a README update: check whether a user reading the README after the release would see outdated advice. If yes, update.

#### 5b. Laravel Boost skills + guidelines

The `.ai/skills/` and `.ai/guidelines/` directories are synced by Laravel Boost (`vendor/bin/testbench package-boost:sync`) to `CLAUDE.md`, `AGENTS.md`, `.claude/skills/`, and `.github/skills/`. Those generated files ship with the package and are read by downstream projects' AI tooling.

Check each edited-or-eligible doc:

- **Accuracy** — every command, path, rule name, and API example must still work against current `main`.
- **Scope** — skills describe *when* to activate and *what steps* to run. Guidelines describe *conventions that persist*. Don't mix.
- **Non-bloat** — prefer tables and bullets over prose. One skill = one clear workflow. Add a new skill rather than overloading an existing one. Delete steps that are no longer load-bearing.
- **Trigger words in frontmatter `description`** — if a new workflow exists, make sure someone typing the natural-language ask can discover the skill.

If any `.ai/` file changed, sync and verify:

```bash
vendor/bin/testbench package-boost:sync || true
git status --short .claude/ .github/ CLAUDE.md AGENTS.md
```

All generated files must be committed together with their `.ai/` sources (per the `ai-guidelines` skill).

### 6. Benchmarks — regression detection

Two benchmark harnesses. Both protect different surfaces and both must be run.

#### 6a. `benchmark.php` — validation hot-path scenarios

`benchmark.php --ci` only emits a `Δ vs base` column when `benchmark-snapshot.json` exists in the working tree. To get a delta locally, mirror `.github/workflows/benchmark.yml`:

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

If you cannot prepare a baseline (detached state, dirty checkout), run `php benchmark.php --ci || true` for absolute numbers and compare visually against the last release's benchmark table via `gh release view <tag>`.

#### 6b. `vendor/bin/pest --group=benchmark` — DB batching + slow-path scenarios

```bash
vendor/bin/pest --group=benchmark || true
```

These tests (in `tests/ImportBenchTest.php`, `tests/SlowPathBenchTest.php`, `tests/RuleSetTest.php`) measure DB query-amplification paths for wildcard `exists`/`unique` rules and slow-path fallbacks. A release can pass `benchmark.php` while silently regressing these.

#### Regression criteria

Flag any of:
- A `benchmark.php` scenario's optimized time increased by **>10%** vs the baseline snapshot (or vs the last release's table, if no local snapshot).
- The speedup multiplier decreased by more than one notch (e.g. ~60x → ~45x).
- A `--group=benchmark` test's reported timing increased noticeably vs prior runs in the conversation or last release.
- Any scenario switched from a fast-check path to a slower path unintentionally.

If any regression is detected:
1. Identify the commit/change that introduced it (`git log -p` on hot-path files: `FastCheckCompiler`, `OptimizedValidator`, `RuleSet`, `WildcardExpander`, `HasFluentRules`, DB batching code).
2. Fix or revert.
3. Re-run the affected harness to confirm recovery.
4. Re-run tests (fix may change semantics).

Run `benchmark.php --ci` **at least twice** — single runs have variance. If the two runs disagree on regression, run a third.

### 7. CI green-light gate (after push, before release notes + tag)

Local green ≠ CI green. The matrix job runs against a Testbench-bootstrapped app in a clean env that usually differs from the dev machine — missing `APP_KEY`, no cached auth user, different PHP/Laravel combos. Local passes frequently, CI fails. A green tag on a red CI is a broken release (see 1.13.1: every Livewire test failed in CI with "No application encryption key has been specified" while 2,081 tests passed locally).

```bash
git push
gh run watch --exit-status || true                       # blocks until the latest run for HEAD finishes
# or, for explicit picking:
gh run list --branch main --limit 1 --json databaseId,status,conclusion
gh run view <id> --json status,conclusion
```

Pass criteria: every job in the workflow matrix reports `conclusion: success`. If ANY conclusion is `failure`, `cancelled`, or `timed_out`:

1. Pull the failure log via `gh run view <id> --log-failed` (or via API if `--log-failed` is empty: `gh api /repos/<owner>/<repo>/actions/jobs/<job-id>/logs`).
2. Reproduce locally — often requires the same env shape as CI (blank APP_KEY, clean composer.lock install, specific PHP/Laravel combo).
3. Fix with a new commit on the same branch.
4. Push and re-run this step.

**Do NOT write release notes or tag until CI is green.** Release notes claim "tests pass on X/Y/Z"; CI is the evidence. Skipping this step reduces downstream trust.

**Exception — push-triggered workflows only:** if CI only runs on `push` (not `pull_request`), then push to main IS the CI trigger and this step runs post-push. For repos with PR-gated CI, push to a feature branch first, wait green, then push-to-main + tag.

## Quick Reference

| Step               | Command                                                          | Pass criteria                             |
|--------------------|------------------------------------------------------------------|-------------------------------------------|
| 1. Rector          | `vendor/bin/rector process \|\| true`                            | 0 files changed                           |
| 2. Pint            | `vendor/bin/pint --dirty --format agent \|\| true`               | clean                                     |
| 3. Tests           | `vendor/bin/pest \|\| true`                                      | 0 failures                                |
| 4. PHPStan         | `vendor/bin/phpstan analyse --memory-limit=2G \|\| true`         | 0 errors                                  |
| 5a. README         | manual scan vs `git log <last-tag>..HEAD`                        | no stale claims; all changed rules listed |
| 5b. Boost docs     | `vendor/bin/testbench package-boost:sync \|\| true`              | `.ai/` ↔ generated files in sync          |
| 6a. Hot-path bench | snapshot baseline → `php benchmark.php --ci \|\| true` (2+ runs) | no >10% regression / speedup-notch drop   |
| 6b. DB-batch bench | `vendor/bin/pest --group=benchmark \|\| true`                    | no timing regression vs last release      |
| 7. CI green-light  | `git push && gh run watch --exit-status`                         | every matrix job `conclusion: success`    |

## Release Notes

Only draft release notes **after all 7 steps pass, including CI green on the pushed commit**. Draft them in `internal/release-notes-<version>.md`, then paste the contents as the GitHub release body. Tag only after release notes exist.

For release notes that claim a performance improvement or regression fix, cite the before/after benchmark numbers explicitly.

**CI handles two things automatically — do not do them manually:**

- **Benchmark table** is appended between `<!-- benchmark-start -->` / `<!-- benchmark-end -->` markers in the release body by `.github/workflows/release-benchmark.yml`. Verify via `gh release view <tag>`.
- **`CHANGELOG.md`** is prepended with the release body by `.github/workflows/update-changelog.yml` on release publish. Do not edit `CHANGELOG.md` manually as part of the release PR. See the `release-automation` guideline for details.

## Important

- Run every step, in order, even if nothing "perf-sensitive" looks changed. Seemingly unrelated refactors (e.g. closure shape, helper method dispatch) have historically introduced 20-40% regressions in the hot path.
- Do not push if any step fails. Fix, then restart the checklist from step 1 — earlier steps may re-break after a later fix.
- Step 5a and 5b are the most common source of silent drift — the README and shipped skills are read by downstream users, and bloat accumulates fast. Delete stale content before adding new.
- Step 6a and 6b are complementary, not redundant: 6a covers validation closure performance, 6b covers DB query amplification. Skipping either leaves a real blind spot.
- Step 7 is the non-skippable gate: CI runs against a clean env (no ambient APP_KEY, no cached auth user, fresh composer install) and frequently catches env-shape bugs that local dev never sees. If the push+watch feels slow, that's the point — waiting 2 minutes for CI green is cheaper than tagging a broken release.
