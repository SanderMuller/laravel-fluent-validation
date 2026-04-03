---
name: implement-spec
description: "Implements a specification file phase-by-phase with progress tracking. Activates when: implementing a spec, building from a spec, starting a spec phase, or when user mentions: implement spec, spec file, implement phase, build spec, start phase."
argument-hint: [spec file path, e.g. specs/wildcard-performance.md]
---

# Implement Spec

Implements a specification file phase-by-phase, tracking progress directly in the spec file.

## When to Use This Skill

Use this skill when:
- Implementing a feature described in a `specs/*.md` file
- The user asks to "implement this spec" or "start the next phase"
- Continuing work on a partially implemented spec

## Workflow Overview

```
Read spec -> Identify phases -> Implement phase -> Check off tasks -> Log findings -> Verify -> Next phase -> Final verification -> Create PR
```

## Step 1: Read and Understand the Spec

1. **Read the full spec file** to understand the complete feature scope.
2. **Locate the implementation section** — look for `## Implementation`.
3. **Identify phases** — look for `### Phase N:` headings within the implementation section.
4. **Determine the current phase** — the first phase with unchecked `- [ ]` tasks is the next to implement.

### Specs Without Phases

Some specs don't have explicit phases — they describe a single focused change. In this case, treat the entire spec as a single phase.

## Step 2: Implement the Current Phase

For each phase:

1. **Read all relevant existing files** before writing any code.
2. **Raise any open questions** from the spec's Open Questions section that affect this phase. Don't make assumptions — ask the user. After the user answers, move it from `## Open Questions` to `## Resolved Questions` with the decision and rationale.
3. **Implement each task** described in the phase.
4. **Check off each task** (`- [x]`) in the spec file as you complete it.
5. **Write tests** for all new functionality — happy paths, failure paths, and edge cases.
6. **Run Pint** on changed files:
   ```bash
   vendor/bin/pint --dirty --format agent
   ```
7. **Run the phase tests** to confirm they pass:
   ```bash
   vendor/bin/pest tests/RelevantTest.php
   ```
8. **Log notes in the Findings section** — record any design decisions, deviations from the spec, or discovered issues.

**Do NOT run PHPStan or the full test suite between phases.** These are slow and only run at Final Verification (Step 3) after all phases are complete.

### Between Phases

After each phase, ask the user if they want to:
- Continue to the next phase
- Review the changes first
- Stop for now

## Step 3: Final Verification (After All Phases Complete)

Once all task checkboxes are checked, use the `backend-quality` skill (Tier 2: full checks).

All checks must pass with 0 errors/failures. Fix any issues and re-run until clean.

## Step 4: Clean Up

After final verification passes, the spec file can be removed as part of PR creation or kept for reference — ask the user.

## Guidelines

- **One phase at a time.** Never implement multiple phases in a single pass.
- **Spec is the source of truth.** Follow the spec's design decisions. If you disagree with a design choice, raise it with the user before deviating.
- **Check off tasks as you go.** The `- [x]` checkboxes in the spec are the single source of progress. Don't leave them for the end of a phase.
- **Log findings.** When you make a design decision, deviate from the spec, or discover something unexpected, add a note to the `## Findings` section.
- **Tests are mandatory.** Every phase must have test coverage before it can be considered complete.
- **Progress must be accurate.** Never check off a task if its tests are failing.
- **Don't skip verification.** The final verification gate exists to catch cross-phase regressions. Run every step.
- **Open questions in the spec** should be raised with the user before implementing the affected section. Don't make assumptions.
- **Future/deferred phases** (marked `Priority: LOW`) should be skipped unless the user explicitly requests them.
