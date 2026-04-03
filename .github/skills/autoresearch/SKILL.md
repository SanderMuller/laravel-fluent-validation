---
name: autoresearch
description: "Autonomous performance optimization loop. Iteratively reduces query count and execution time by modifying code, benchmarking, and keeping/reverting changes. Activates when: optimizing performance, reducing overhead, improving execution time, benchmarking, or when user mentions: autoresearch, optimize, performance, benchmark."
argument-hint: "[description of what to optimize]"
---

# Autoresearch — Autonomous Performance Optimization

Inspired by [Karpathy's autoresearch](https://github.com/karpathy/autoresearch). Applies constraint-driven autonomous iteration to reduce execution time and overhead for any measurable code path.

**Core idea:** Modify one thing, benchmark, keep if improved, revert if not, repeat.

## Subcommands

| Subcommand | Purpose |
|------------|---------|
| `/autoresearch` | Run the autonomous optimization loop |
| `/autoresearch:plan` | Interactive wizard: analyze bottlenecks and set up benchmark + research doc |

## When to Activate

- User invokes `/autoresearch` or mentions autoresearch
- User wants to reduce execution time for a specific operation
- User says "optimize", "slow", "benchmark", "performance"
- Any task requiring iterative performance improvement with measurable outcomes

## Directory Structure

All autoresearch artifacts live in `autoresearch/` (gitignored):

```
autoresearch/
├── {slug}-research.md          # Research document (bottlenecks, scope, constraints)
├── {slug}-bench.php            # Benchmark script (measures metrics)
├── {slug}-progress.md          # Iteration log — updated after EVERY attempt
└── patches/                    # Saved diffs of successful optimizations
    ├── 001-description.patch
    └── ...
```

Use kebab-case slugs derived from the target (e.g., `wildcard-expansion`, `ruleset-compilation`).

---

## /autoresearch:plan — Setup Wizard

### Step 1: Identify the Target

Ask the user what to optimize, or accept it as an argument. The target can be:

- **A method/class** — trace the execution flow
- **An existing benchmark** — use `benchmark.php` as a starting point
- **Any code path** — identify the entry point and trace the execution flow

### Step 2: Baseline Measurement

Create a benchmark script at `autoresearch/{slug}-bench.php` that:

1. **Bootstraps the test environment** using Orchestra Testbench
2. **Creates realistic test data** — cover the "fully loaded" scenario
3. **Runs a warmup iteration** to prime caches
4. **Benchmarks 5 iterations**, measuring:
   - `execution_median_ms` — median execution time via `hrtime(true)`
5. **Outputs METRIC lines to stdout** (machine-readable)
6. **Outputs diagnostics to stderr** (human-readable breakdown)

Template for METRIC output:
```
METRIC execution_median_ms={N.NN}
METRIC execution_mean_ms={N.NN}
```

### Step 3: Analyze Bottlenecks

Run the benchmark and document bottlenecks in `autoresearch/{slug}-research.md`:

```markdown
# Autoresearch: {Description} Performance Optimization

## Objective

{What is being optimized and why it matters.}

## Scope

Files that may be modified:

- `path/to/File.php` — {why}

## Baseline Measurements

| Scenario | Execution Time |
|----------|---------------|
| {scenario} | ~{N}ms |

## Known Bottlenecks

1. **{Description}** — {explanation}

## Constraints

- Existing tests must pass
- Public API must remain unchanged
- No new dependencies

## Strategies Attempted

(Updated as experiments are conducted)

## Results

(Updated with final measurements)
```

### Step 4: Record Baseline

Create the progress file at `autoresearch/{slug}-progress.md`:

```markdown
# Autoresearch Progress: {slug}

**Baseline:** {N}ms

| # | Commit | Time (ms) | Status | Description |
|---|--------|-----------|--------|-------------|
| 0 | — | {N} | baseline | initial state |
```

### Step 5: Confirm and Launch

Present the research document and baseline to the user. Ask:

1. Are the scope constraints correct?
2. Are there any files that should NOT be modified?
3. Should I start the optimization loop now?

---

## /autoresearch — The Optimization Loop

### Prerequisites

Verify research doc, benchmark script, and baseline exist. If missing, run `/autoresearch:plan` first.

### The Loop

```
LOOP (until interrupted or goal achieved):
  1. REVIEW  — Read research doc, progress file, git history
  2. IDEATE  — Pick the next bottleneck to address
  3. MODIFY  — Make ONE focused change to in-scope files
  4. COMMIT  — Git commit before verification (enables clean revert)
  5. VERIFY  — Run benchmark, capture METRIC lines + run tests
  6. DECIDE  — Keep if improved, revert if same/worse
  7. LOG     — Update progress file IMMEDIATELY (not in bulk)
  8. REPEAT
```

### Phase 1: Review

Before each iteration:
1. Read the research document for bottleneck context
2. Read the progress file — check what worked/failed
3. Check recent git history: `git log --oneline -10`

### Phase 2: Ideate

Pick the next optimization. Priority order:
1. **Fix crashes** from previous iteration
2. **Exploit successes** — if last change helped, try variants
3. **Address highest-impact bottleneck**
4. **Combine near-misses** — two changes that individually didn't help might work together
5. **Simplify** — remove code while maintaining metric

### Phase 3: Modify

Make ONE focused change. Write a one-sentence description BEFORE modifying code.

### Phase 4: Commit

```bash
git add <changed-files>
git commit -m "autoresearch: <one-sentence description>"
```

### Phase 5: Verify

Run the benchmark and related tests:

```bash
php autoresearch/{slug}-bench.php 2>/dev/null || true
vendor/bin/pest --filter={related_test} || true
```

### Phase 6: Decide

```
IF improved AND tests pass:
    STATUS = "keep"
    Save patch: git diff HEAD~1 HEAD > autoresearch/patches/{NNN}-{description}.patch

ELIF NOT improved OR tests fail:
    STATUS = "discard"
    git reset --hard HEAD~1
```

### Phase 7: Log — Update Progress File IMMEDIATELY

Append a row after EVERY iteration:

```markdown
| {N} | {hash or —} | {ms} | {keep/discard/crash} | {description} |
```

### Phase 8: Repeat

Print a status line every 5 iterations:

```
=== Iteration 10: 210ms (was 265ms), 6 keeps / 4 discards ===
```

### When Stuck (>5 consecutive discards)

1. Re-read ALL in-scope files from scratch
2. Re-read the research document
3. Run a fresh benchmark with full diagnostics
4. Look for NEW bottlenecks not in the original list
5. Try the OPPOSITE approach

### Completion

1. Print final summary
2. Update the research document's "Results" section
3. Run Pint on all modified files
4. Run the full test suite to confirm nothing is broken
5. Present the optimizations to the user for review

---

## Critical Rules

1. **ONE change per iteration** — atomic changes so you know what helped
2. **Mechanical verification only** — benchmark numbers, not "looks better"
3. **Automatic rollback** — failed changes revert instantly
4. **Tests must pass** — an optimization that breaks tests is not an optimization
5. **Respect scope** — only modify files listed in the research document
6. **Git is memory** — commit before verify, revert on failure
7. **Don't ask "should I continue?"** — keep iterating until stuck or done
8. **Log immediately** — update progress after EVERY iteration
