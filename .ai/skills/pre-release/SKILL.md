---
name: pre-release
description: "Pre-push / pre-release checklist. Runs Rector, Pint, full test suite, PHPStan, audits README + `.ai/` docs for staleness, and runs both benchmark harnesses (benchmark.php + --group=benchmark). Activate before: pushing to remote, tagging a release, writing release notes, or when user mentions: pre-release, pre-push, release checklist, ship, cut release, release notes."
---

# Pre-Release Checklist

Run this full gauntlet before pushing commits that may be tagged as a release or before drafting release notes. It catches regressions the two-tier `backend-quality` skill skips — Rector drift, stale docs shipped to downstream projects, and performance regressions in both validation hot paths and DB-batching paths.

## When to Use This Skill

Activate when:
- About to push commits that will land in a release
- About to write or update release notes
- User says "ship it", "cut a release", "pre-push", "release checklist"
- A feature/fix is fully implemented and quality-gated

Do NOT use mid-development — this is a completion-level skill.

**The user cuts the tag, not you.** The user runs `git tag` and creates the GitHub release themselves — tagging is irreversible-ish and a release-visibility decision that the user owns. Do NOT suggest, demonstrate, or execute tag/release-create commands. State that the release is ready to tag and leave the tag creation to the user. The skill's job ends only once step 9b (post-tag watch) has confirmed the tag-ref and release-event workflows are green — "tag cut" is not the finish line.

## Workflow

Run the checks **in this order**. Each must pass before moving to the next. Fix issues as they surface; do not batch.

Always append `|| true` to verification commands so output is captured even on failure (per repo `CLAUDE.md` rule). Pass/fail is determined from the captured output, not the exit status alone.

**The order is 1 → 2 → 3 → 4 → 5 → 6 → commit → push → 7 → 8 (draft notes) → user cuts tag → 9a (pre-tag gate, just before `gh release create`) → 9b (post-tag watch).** Do not jump from step 6 straight to drafting release notes. The release-notes file is written only after the changes have been committed, pushed, and CI is green on that exact SHA (step 7). Writing notes earlier claims facts ("tests pass on CI matrix", "2,092 tests / 2,941 assertions") that are not yet proven. If you find yourself about to `Write` a file under `internal/release-notes-<version>.md` and the last thing you did was run benchmarks, stop — you skipped commit/push/CI. And if the tag is cut without step 9a's live-remote + CI re-check, or without waiting on step 9b's tag-ref runs, the release ships on unverified facts even if steps 1-8 all passed.

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

**Local green ≠ CI green for this step.** Pest runs parallel on one OS/PHP/Laravel combo locally. The CI matrix includes Windows + `prefer-lowest` legs where `pest --parallel` has historically raced filesystem ops (e.g. `PackageManifest::write()` → `rename()`), producing failures invisible on macOS. Do not let a local pass relax step 7 rigor — step 7 is the authoritative test gate across the matrix. See 1.17.1: local green, Windows P8.2 `prefer-lowest` red, tag cut anyway because step 7 was skipped.

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

**Scope is per-commit, not per-run.** This repo has multiple workflows with different triggers — `gh run watch` follows a single run and will silently skip other workflows that also have opinions about the same SHA. Enumerate by commit SHA and wait for every matching run:

```bash
git push
SHA=$(git rev-parse HEAD)

# Settle — GitHub takes several seconds to register runs against the new SHA.
# Querying too early returns an empty list; `running=0` would falsely signal green.
sleep 20

# List every workflow run tied to this SHA, across all workflows/triggers
gh run list --commit "$SHA" --json databaseId,name,event,status,conclusion

# Wait for every run to reach a terminal state, then assert all success.
# `total > 0` guard prevents the empty-list → zero-running false green.
while true; do
    total=$(gh run list --commit "$SHA" --json databaseId -q 'length')
    running=$(gh run list --commit "$SHA" --json status -q '[.[] | select(.status != "completed")] | length')
    [ "$total" -gt 0 ] && [ "$running" -eq 0 ] && break
    sleep 15
done

failed=$(gh run list --commit "$SHA" --json conclusion,name -q '[.[] | select(.conclusion != "success" and .conclusion != "skipped")] | length')
[ "$failed" -eq 0 ] || { echo "CI red on $SHA"; gh run list --commit "$SHA"; exit 1; }
```

Pass criteria: every run for this commit has `conclusion` in `{success, skipped}`. Skipped is fine — path-filtered workflows (e.g. `on: push: paths: ['**.php']`) are expected to skip when the release commit touches docs only.

**Don't rely on a "latest run" heuristic.** `gh run list --branch main --limit 1` may pick a run from a completely different push — the commit-SHA filter is the only reliable anchor.

On failure:

1. Pull the failure log via `gh run view <id> --log-failed` (or via API if `--log-failed` is empty: `gh api /repos/<owner>/<repo>/actions/jobs/<job-id>/logs`).
2. Reproduce locally — often requires the same env shape as CI (blank APP_KEY, clean composer.lock install, specific PHP/Laravel combo).
3. Fix with a new commit on the same branch.
4. Push and re-run step 7 against the new HEAD.

**Do NOT write release notes until CI is green.** Release notes claim "tests pass on X/Y/Z"; CI is the evidence. Skipping this step reduces downstream trust. (The user handles tag creation — once release notes are drafted against a green CI, the skill's job is done.)

**Workflows only triggered by PR (e.g. `benchmark.yml` with `on: pull_request`)** won't appear in the commit-SHA enumeration for a direct push-to-main. That's intentional — those workflows guarded the merge, not the release. If a workflow is release-critical (not pre-merge-critical), change its trigger to `push` + `pull_request` so it runs on both.

**Workflows triggered by `release` (e.g. `release-benchmark`, `update-changelog`)** run AFTER tag creation, not before. They're outside this gate by design — their job is to decorate the release after it ships, not to gate whether it ships.

**`on: push` workflows re-fire on the tag-ref push.** Creating a release pushes a tag ref; any workflow that triggers on `push` (including `run-tests`) runs again against that tag ref. Those runs are *not* part of this pre-tag gate — they happen after tag creation. They can surface environment-shape failures (Windows fs races, prefer-lowest combos) that the main-branch run narrowly missed. Step 9 (post-tag watch) handles them.

### 8. Release notes (ONLY after step 7 CI-green)

This is where agents most commonly slip: running the local gauntlet (steps 1-6), then jumping straight to `Write internal/release-notes-<version>.md` without committing, pushing, or watching CI. **Do not do that.** Notes claim CI-matrix facts; CI must have produced those facts first.

**Release notes are public artefacts — do NOT name or reference peers.** The release body is rendered on GitHub, prepended to `CHANGELOG.md` by CI, and indexed by Packagist. Anything written here is visible to every downstream consumer and shows up in search. Internal peer instances (`e0cp6lq3`, `2op9yaul`, etc.), peer-level adoption reports, and claude-peers channels are *process* concerns, not product concerns — consumers don't know or care about them, and leaking the IDs exposes internal architecture.

**What not to write:**

- Peer IDs: ~~"sourced from peer `e0cp6lq3`"~~, ~~"hihaho peer confirmed"~~
- Instance/channel framing: ~~"peer instance adoption report"~~, ~~"via claude-peers dogfood"~~
- Claude-Code-internal phrasing in general: ~~"agent-driven"~~, ~~"via the rector companion peer"~~

**What to write instead:**

- Generic adoption framing: "sourced from production dogfood", "real-world adoption feedback", "consumer usage audit"
- Named public contributors only: GitHub usernames / real-name contributors who filed issues, PRs, or are otherwise publicly part of the conversation. If you have an external user or named downstream app that consented to being credited, name them. Otherwise, stay generic.
- The technical reasoning (why the decision was made) without tying it to a specific internal agent session.

**Scope of the rule:** applies to every file written under `internal/release-notes-<version>.md`, since that body text flows directly to the public GitHub release + CHANGELOG. Internal planning files (`internal/roadmap.md`, `internal/specs/*.md`) CAN reference peer IDs — those stay out of the package's git history (`internal/` is gitignored) and are legitimate session-to-session continuity aids.

**Quick scrub checklist before `Write`ing the notes file:** grep your draft for `peer`, any 8-character alphanumeric sequence that looks like a peer ID (`[a-z0-9]{8}`), and "claude-peers" / "claude-code". If any match, rewrite or delete the phrase before saving.

**Preflight — run these three commands and confirm all three before you create the release-notes file.** If any fail, you are not ready to draft notes; go back to whichever earlier step is incomplete.

```bash
# 1. Working tree must be clean — the commit landed, nothing is uncommitted
git status --short || true

# 2. HEAD must be pushed — local SHA == origin/main SHA
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && echo "pushed" || echo "NOT pushed"

# 3. Every CI run for this SHA must be terminal + {success, skipped} — same query as step 7
SHA=$(git rev-parse HEAD)
gh run list --commit "$SHA" --json name,status,conclusion
```

Only when (1) status is empty, (2) echoes `pushed`, and (3) every run is `completed` + `{success, skipped}` may you `Write` to `internal/release-notes-<version>.md`.

Draft into `internal/release-notes-<version>.md`. The user reads the draft, creates the tag, and publishes the release themselves — do not cut the tag, do not run `gh release create`, do not push tags. Once the release-notes file exists and CI is green, report "ready to tag" and stop.

**Pin the verified SHA in the notes file.** The very first line of the notes file must be an HTML comment recording the green SHA. GitHub strips HTML comments when rendering the release body, so this is invisible to readers but greppable by step 9:

```markdown
<!-- verified-sha: 4387b6845b45def9c6ad80e638990f81b74bfb19 -->

# <version>

...
```

The SHA in that line is the exact `git rev-parse HEAD` that step 7 proved green. Step 9's pre-tag gate fails closed if the SHA in the notes file does not match the current HEAD (i.e. someone landed more commits between notes draft and tag).

For release notes that claim a performance improvement or regression fix, cite the before/after benchmark numbers explicitly.

**CI handles two things automatically — do not do them manually:**

- **Benchmark table** is appended between `<!-- benchmark-start -->` / `<!-- benchmark-end -->` markers in the release body by `.github/workflows/release-benchmark.yml`. Verify via `gh release view <tag>`.
- **`CHANGELOG.md`** is prepended with the release body by `.github/workflows/update-changelog.yml` on release publish. Do not edit `CHANGELOG.md` manually as part of the release PR. See the `release-automation` guideline for details.

### 9. Pre-tag gate + post-tag watch (the step 1.17.1 lacked)

Step 7 proves CI green at draft time. Step 9 proves CI is *still* green at tag time, and catches failures that only show up on the tag-ref push.

**9a. Pre-tag gate — run immediately before `gh release create` / GitHub release publish.** This is the one-liner the user runs (not the agent) in the same terminal, seconds before cutting the tag. It re-verifies three things: HEAD hasn't drifted since notes draft, the notes file pins this exact SHA, and CI is still all green.

```bash
SHA=$(git rev-parse HEAD)
VERSION="<version>"  # e.g. 1.17.2
NOTES="internal/release-notes-${VERSION}.md"

# A. Notes file exists and pins this SHA (anchored regex — tolerant of surrounding blank lines,
#    strict about the line itself so a rewrite of the SHA breaks the gate)
grep -qE "^<!-- verified-sha: $SHA -->$" "$NOTES" || { echo "NOTES SHA DRIFT — HEAD=$SHA, notes say $(grep verified-sha "$NOTES")"; exit 1; }

# B. HEAD matches the LIVE remote tip of main — not the cached tracking ref.
#    `git rev-parse origin/main` is stale until an explicit fetch and would
#    let a concurrent push slip through. `ls-remote` always hits the remote.
LIVE_TIP=$(git ls-remote origin refs/heads/main | awk '{print $1}')
[ "$SHA" = "$LIVE_TIP" ] || { echo "HEAD DRIFT — HEAD=$SHA live origin/main=$LIVE_TIP"; exit 1; }

# C. Every CI run for this SHA still terminal + {success, skipped}
failed=$(gh run list --commit "$SHA" --json conclusion -q '[.[] | select(.conclusion != "success" and .conclusion != "skipped")] | length')
running=$(gh run list --commit "$SHA" --json status -q '[.[] | select(.status != "completed")] | length')
[ "$running" -eq 0 ] && [ "$failed" -eq 0 ] || { echo "CI NOT GREEN — running=$running failed=$failed"; gh run list --commit "$SHA"; exit 1; }

echo "OK to tag $VERSION at $SHA"
```

The `ls-remote` call is the key difference from the step 8 preflight, which uses the local tracking ref `origin/main`. Step 8 runs right after push when the tracking ref is fresh; step 9a can run minutes or hours later, after the user has context-switched, and the only safe way to prove HEAD is still the tip is to ask the remote directly.

If any check fails, do NOT tag. Fix the drift / failure, re-run steps 7-8 for the new SHA, then retry 9a.

**9b. Post-tag watch.** Creating the release pushes a tag ref, which re-fires `on: push` workflows (including `run-tests`) against that ref. Watch those runs — they are not part of the pre-tag gate and can fail even when 9a passed (Windows fs races, prefer-lowest combos that narrowly missed the main-branch run). Also watch `release`-event decorators (`release-benchmark`, `update-changelog`).

**Do not use `gh run list --branch "$TAG"`.** The `--branch` flag's semantics for tag refs are undocumented — it sometimes works, sometimes returns empty. The reliable selector is the *tag's commit SHA* plus a jq filter on `headBranch == $TAG`. Both `push`-event (tag-ref re-fire) and `release`-event runs attach to that SHA with `headBranch` set to the tag name.

**Run 9b strictly after `gh release create` has completed.** The tag must already exist on the remote; fetch it locally before resolving the SHA.

```bash
TAG="$VERSION"
git fetch --tags origin --quiet
TAG_SHA=$(git rev-list -n 1 "$TAG")  # the commit the tag points at

# All runs attached to the tag SHA, then filter to those whose headBranch is the tag ref.
# This cleanly picks up both the `push`-event tag re-fires and the `release`-event decorators,
# and excludes the main-branch runs that step 7 already gated.
gh run list --commit "$TAG_SHA" \
  --json databaseId,name,event,headBranch,status,conclusion \
  -q "[.[] | select(.headBranch == \"$TAG\")]"

# Wait until every tag-scoped run is terminal, then assert all success.
# `waited` bounds the loop at ~15 min so a hung run doesn't block indefinitely.
# If `total` stays 0 past the first 90s, the tag was likely created without firing
# push/release workflows (rare — e.g. re-using an existing tag) — investigate manually.
waited=0
while [ "$waited" -lt 900 ]; do
    running=$(gh run list --commit "$TAG_SHA" --json status,headBranch \
      -q "[.[] | select(.headBranch == \"$TAG\") | select(.status != \"completed\")] | length")
    total=$(gh run list --commit "$TAG_SHA" --json databaseId,headBranch \
      -q "[.[] | select(.headBranch == \"$TAG\")] | length")
    [ "$total" -gt 0 ] && [ "$running" -eq 0 ] && break
    sleep 15
    waited=$((waited + 15))
done
[ "$total" -gt 0 ] || { echo "NO TAG-REF RUNS after ${waited}s — investigate"; exit 1; }

failed=$(gh run list --commit "$TAG_SHA" --json conclusion,headBranch,name \
  -q "[.[] | select(.headBranch == \"$TAG\") | select(.conclusion != \"success\" and .conclusion != \"skipped\")] | length")
[ "$failed" -eq 0 ] || { echo "TAG-REF CI RED on $TAG ($TAG_SHA)"; gh run list --commit "$TAG_SHA"; exit 1; }
```

Wait until terminal. If red:
1. Investigate (same as step 7 failure drill).
2. If the failure reveals a real bug (not just flake): fix on `main`, cut a patch release (`1.17.2` after `1.17.1`). Do not rewrite the tag.
3. If `update-changelog` failed: `CHANGELOG.md` won't be prepended — re-run the workflow once the underlying cause is fixed, or prepend the entry manually (rare).

**Rule:** the skill is not "done" until 9b goes green. "Tag cut" is not the finish line; "tag-ref CI green + release-event workflows green" is.

## Quick Reference

| Step               | Command                                                                                  | Pass criteria                                 |
|--------------------|------------------------------------------------------------------------------------------|-----------------------------------------------|
| 1. Rector          | `vendor/bin/rector process \|\| true`                                                    | 0 files changed                               |
| 2. Pint            | `vendor/bin/pint --dirty --format agent \|\| true`                                       | clean                                         |
| 3. Tests           | `vendor/bin/pest \|\| true`                                                              | 0 failures                                    |
| 4. PHPStan         | `vendor/bin/phpstan analyse --memory-limit=2G \|\| true`                                 | 0 errors                                      |
| 5a. README         | manual scan vs `git log <last-tag>..HEAD`                                                | no stale claims; all changed rules listed     |
| 5b. Boost docs     | `vendor/bin/testbench package-boost:sync \|\| true`                                      | `.ai/` ↔ generated files in sync              |
| 6a. Hot-path bench | snapshot baseline → `php benchmark.php --ci \|\| true` (2+ runs)                         | no >10% regression / speedup-notch drop       |
| 6b. DB-batch bench | `vendor/bin/pest --group=benchmark \|\| true`                                            | no timing regression vs last release          |
| **commit + push**  | user confirms changes + `git push`                                                       | HEAD pushed to `origin/main`                  |
| 7. CI green-light  | `gh run list --commit "$(git rev-parse HEAD)"` all complete + no failure                 | every run for the SHA in `{success, skipped}` |
| 8. Release notes   | preflight (clean tree + pushed + CI green) → `Write internal/release-notes-<version>.md` | first line is `<!-- verified-sha: $SHA -->`   |
| 9a. Pre-tag gate   | one-liner asserts SHA-drift, push state, CI-still-green immediately before `gh release create` | prints `OK to tag`                      |
| 9b. Post-tag watch | `gh run list --commit "$TAG_SHA"` filtered by `headBranch == $TAG`                       | tag-ref + release-event workflows all green   |

## Important

- Run every step, in order, even if nothing "perf-sensitive" looks changed. Seemingly unrelated refactors (e.g. closure shape, helper method dispatch) have historically introduced 20-40% regressions in the hot path.
- Do not push if any step fails. Fix, then restart the checklist from step 1 — earlier steps may re-break after a later fix.
- Step 5a and 5b are the most common source of silent drift — the README and shipped skills are read by downstream users, and bloat accumulates fast. Delete stale content before adding new.
- Step 6a and 6b are complementary, not redundant: 6a covers validation closure performance, 6b covers DB query amplification. Skipping either leaves a real blind spot.
- Step 7 is the non-skippable gate: CI runs against a clean env (no ambient APP_KEY, no cached auth user, fresh composer install) and frequently catches env-shape bugs that local dev never sees. If the push+watch feels slow, that's the point — waiting 2 minutes for CI green is cheaper than tagging a broken release.
- Step 8 (release notes) is gated by step 7 — **the release-notes file must not exist on disk until CI is green on the pushed commit.** If you catch yourself about to `Write` a release-notes file after running benchmarks locally, stop: you are about to fabricate facts that the CI matrix has not yet established. Run the step-8 preflight commands first; if any of the three conditions is not satisfied, the draft is premature.
- Step 9 closes the 1.17.1 gap. 9a re-verifies the live remote tip (`git ls-remote`, not the cached `origin/main`) so a concurrent push can't slip a stale commit through. 9b uses `--commit "$TAG_SHA"` + a jq filter on `headBranch == $TAG` (not `--branch "$TAG"`, whose tag-ref semantics are undocumented and unreliable) so the tag-ref `on: push` re-fires and `on: release` decorators are both caught. Run both every time, even for one-commit patch releases.
- `pest --parallel` on Windows `prefer-lowest` has a known FS race in `PackageManifest::write()` → `rename()`. Do not assume local parallel-pest green proves CI-matrix green. Step 7 + 9b are the authoritative test gates.
