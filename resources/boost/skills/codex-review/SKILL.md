---
name: codex-review
description: "Requests an independent code review from OpenAI Codex CLI, critically evaluates its findings, and applies warranted fixes. Activates when: the user says /codex-review, asks for a Codex review, or wants an external AI review of changes."
user_invocable: true
---

# Codex Code Review

Run an independent code review using OpenAI Codex CLI, then critically evaluate and apply warranted findings.

## Step 1: Determine what to review

Check what has changed:

```bash
git diff --stat HEAD
git diff --stat --staged
```

If there are uncommitted changes, review those (`--uncommitted`). If the working tree is clean, review the latest commit (`--commit HEAD`).

## Step 2: Run Codex review

Run the appropriate command:

**For uncommitted changes:**
```bash
codex exec review --full-auto --uncommitted "Review for correctness, security, edge cases, and test coverage gaps. This is a Laravel validation package. Focus on: rule compilation ordering, fast-check correctness, FormRequest lifecycle compatibility, and cross-field wildcard handling. Be concise — only report real issues, not style preferences."
```

**For the latest commit:**
```bash
codex exec review --full-auto --commit HEAD "Review for correctness, security, edge cases, and test coverage gaps. This is a Laravel validation package. Focus on: rule compilation ordering, fast-check correctness, FormRequest lifecycle compatibility, and cross-field wildcard handling. Be concise — only report real issues, not style preferences."
```

**For changes against main:**
```bash
codex exec review --full-auto --base main "Review for correctness, security, edge cases, and test coverage gaps. This is a Laravel validation package. Focus on: rule compilation ordering, fast-check correctness, FormRequest lifecycle compatibility, and cross-field wildcard handling. Be concise — only report real issues, not style preferences."
```

## Step 3: Critically evaluate findings

Codex findings are suggestions, not mandates. For each finding:

1. **Is it a real bug?** — Verify by reading the code. Don't trust Codex's assessment blindly.
2. **Is it already tested?** — Check if existing tests cover the scenario.
3. **Is it a style preference?** — Skip. Don't change working code for style.
4. **Is it a false positive?** — Codex may misunderstand Laravel internals or the package's architecture. Verify against the actual behavior.

## Step 4: Apply warranted fixes

For findings that are genuine issues:

1. Fix the code
2. Run `vendor/bin/pest --no-coverage` to verify
3. Run `vendor/bin/phpstan analyse src/ --memory-limit=2G` to verify

## Step 5: Report

Summarize to the user:

```markdown
## Codex Review Summary

### Applied
- [Issue] — [What was wrong and how you fixed it]

### Dismissed
- [Finding] — [Why it was dismissed: false positive / already tested / style preference]

### No Issues
- [Categories that were clean]
```