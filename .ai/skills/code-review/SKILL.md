---
name: code-review
description: "Reviews recent code changes for improvements across functionality, code quality, security, and testing. Activates when: the user asks to review implementation, review changes, review code, audit code, check for improvements, or when user mentions: review, audit, improvements, code quality, code review."
argument-hint: [file path, feature name, or description of changes]
---

# Code Review

A structured review of recent code changes, evaluating them across multiple quality dimensions and producing a prioritized list of actionable findings.

## When to Use This Skill

Use this skill when:
- Reviewing a recently implemented feature or change
- Auditing code before committing or creating a PR
- The user asks "can you review this" or "are there improvements"
- Checking implementation quality after a subagent completes work

Do NOT use for:
- Reviewing code you haven't read yet (read first, then review)
- General codebase audits with no specific scope
- Reviewing third-party packages

## Workflow

### Phase 1: Identify the Scope

Determine what to review:

1. **If given specific files**: read those files
2. **If given a feature description**: find all related files (src/, tests/)
3. **If no scope specified**: use `git diff` or `git diff --cached` to find recently changed files

Read ALL files in scope before starting the review. Do not review code you haven't read.

### Phase 2: Review Each Dimension

Evaluate the changes against each category below. Only report findings where there is a **clear, concrete improvement** — do not nitpick or pad the review.

#### Security

Look for:
- **Input validation**: Unvalidated user input reaching critical paths
- **Type safety**: Loose comparisons where strict is needed, missing type declarations
- **Information disclosure**: Sensitive data in error messages or logs

#### Functionality

Look for:
- **Logic errors**: Conditions that don't match intent, off-by-one errors, wrong operator
- **Missing edge cases**: Null handling, empty collections, zero values, boundary conditions
- **Duplicate work**: Same computation running multiple times unnecessarily
- **Broken flows**: Actions that silently fail, missing error handling for expected failures
- **Race conditions**: Concurrent requests causing data corruption (missing locks, non-atomic operations)
- **Laravel compatibility**: Does the code work across all supported Laravel versions (11, 12, 13)?
- **PHP compatibility**: Does the code work across all supported PHP versions (8.2, 8.3, 8.4)?

#### Code Quality & Maintenance

Look for:
- **Project convention violations**: Does the code follow established patterns? Check sibling files.
- **Unnecessary complexity**: Could the same result be achieved more simply?
- **DRY violations**: Duplicated logic that should be extracted
- **Dead code**: Unused variables, unreachable branches, commented-out code
- **Type safety**: Missing return types, loose comparisons where strict is needed
- **Naming**: Do names clearly communicate intent?
- **Separation of concerns**: Business logic mixed with validation logic, etc.

#### Testing

Look for:
- **Missing happy path tests**: Core functionality not tested
- **Missing failure path tests**: Error cases, invalid input
- **Missing edge case tests**: Boundary values, empty data, null values
- **Fragile assertions**: Tests that pass for the wrong reason
- **Test isolation**: Tests that depend on each other or on specific state

### Phase 3: Compile Findings

Present findings in a structured format:

1. **Group by category** (Security, Functionality, etc.)
2. **Number each finding** for easy reference
3. **Include file + line number** for each finding
4. **Explain the issue** concisely — what's wrong and why it matters
5. **Skip categories with no findings** — don't add filler

### Phase 4: Prioritize

End with a summary table ranking findings by severity:

| Severity | Meaning                                                                     |
|----------|-----------------------------------------------------------------------------|
| High     | Security vulnerabilities, data corruption risks, broken functionality       |
| Medium   | Missing tests for important paths, edge cases that could cause issues       |
| Low      | Minor inconsistencies, polish items, nice-to-have improvements              |

## Output Format

```markdown
### Security

**1. [Short title]**
`file/path.php:42` — Description of the issue and why it matters.

### Functionality

**2. [Short title]**
`file/path.php:18` — Description...

### Testing

**3. [Short title]**
`tests/FooTest.php` — Description...

---

| # | Finding | Severity |
|---|---------|----------|
| 1 | Short title | High |
| 2 | Short title | Medium |
| 3 | Short title | Low |
```

## Guidelines

- **Be concrete**: Every finding must point to a specific line or pattern in the code. No vague suggestions.
- **Be actionable**: Each finding should make clear what needs to change.
- **Be proportional**: Don't flag style preferences as security issues. Match severity to actual impact.
- **Respect existing conventions**: If the codebase uses a pattern consistently, don't flag it as an issue even if you'd do it differently.
- **Don't pad**: If a category has no findings, skip it entirely. A short review with real findings is better than a long review with filler.
- **Read before reviewing**: Never review code you haven't read in this session. If you need more context, read the relevant files first.
