---
name: evaluate
description: "Evaluate the entire implementation and fix any issues that you find. If you find any issues, please fix them yourself. Only ask the user when you need a decision. Activates when: evaluating implementation, self-reviewing code, checking for issues, or when user mentions: evaluate, check implementation, self-review, verify implementation."
argument-hint: "[file path, feature name, or description of what to evaluate]"
---

# Evaluate Implementation

A self-directed loop: evaluate your own work, fix what you find, re-evaluate until clean, then run a code review for a fresh-eyes pass. Do not ask the user to fix things — fix them yourself.

## When to Use This Skill

- After implementing a feature or fixing a bug (all code is written)
- When the user says "evaluate", "check this", or "review your work"
- Before creating a PR or marking work as done

**Note:** This skill is a completion-level activity. It runs the full `backend-quality` skill (including PHPStan and full test suite). Do not use this skill mid-feature — only when the implementation is done.

## Workflow

### Phase 1: Run Quality Checks (Skip If Recent)

Before running checks, review the current conversation for recent quality check results. **Skip checks that already passed clean and where no code changes were made since.**

**Skip criteria — all must be true:**
1. The check was run earlier in this conversation (not a previous session)
2. The check passed with zero errors/failures
3. No files of that type were added, removed, or changed after the check passed

**What counts as "recently passed":**
- Pint: `vendor/bin/pint --dirty --format agent` ran with no changes needed
- PHPStan: `vendor/bin/phpstan analyse --memory-limit=2G` ran with 0 errors
- Tests: `vendor/bin/pest` ran with 0 failures (full suite or all relevant tests)

**If checks can be skipped**, state which specific checks you're skipping and why:
> "Skipping Pint and PHPStan — both passed clean earlier with no PHP changes since; re-running tests to verify behavior."

**If any doubt**, run the checks. It's better to re-run than to miss a failure.

**Otherwise**, use the `backend-quality` skill.

Fix all failures before continuing.

### Phase 2: Review for Issues

Read through all changed files and check for:

| Category | What to look for |
|----------|-----------------|
| **Edge cases** | Null handling, empty collections, zero values, boundary conditions |
| **Race conditions** | Concurrent requests causing data corruption, non-atomic operations |
| **Security** | Unvalidated input, type confusion |
| **Logic errors** | Wrong conditions, off-by-one errors, swallowed exceptions |
| **Missing tests** | Happy paths, failure paths, and edge cases that aren't tested |
| **Convention violations** | Deviations from project patterns (check sibling files) |
| **Cross-version compat** | Works on PHP 8.2-8.4 and Laravel 11-13 |

### Phase 3: Fix Issues

For each issue found:

1. Fix it yourself — do not list it as a suggestion for the user
2. Run the affected tests again to verify the fix
3. If the fix requires a design decision, ask the user

### Phase 4: Re-evaluate (Loop Until Clean)

After fixing issues, re-run only the checks affected by your fixes. Repeat until a full pass finds no new issues. Only then move to Phase 5.

### Phase 5: Code Review

Once the evaluate-fix loop is clean, run the `code-review` skill for a structured review from a different angle (functionality, security, testing). Fix any findings from the code review and re-verify.

### Phase 6: Report

Summarize what you found and fixed across all passes:

```markdown
## Evaluation Summary

### Issues Found & Fixed
1. **[Issue]** — [What was wrong and how you fixed it]

### Verified
- All tests pass (X tests, Y assertions)
- PHPStan clean
- Code style clean

### No Issues Found In
- [Categories that were clean]
```

If no issues were found, say so briefly and move on.

## Guidelines

- **Fix, don't report** — the point of this skill is to catch and fix issues, not to generate a list for the user
- **Loop until clean** — do not stop after the first fix pass; re-evaluate until nothing remains
- **Be thorough but fast** — check all dimensions but don't over-analyze obvious code
- **Run tests after every fix** — don't batch fixes and hope they all work
- **Trust existing patterns** — if the codebase does something a certain way consistently, follow it
