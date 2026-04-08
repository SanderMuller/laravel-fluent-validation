---
name: codex-review
description: "Requests an independent code review from OpenAI Codex CLI, critically evaluates its findings, and applies warranted fixes. Activates when: the user says /codex-review, asks for a Codex review, or wants an external AI review of changes."
user_invocable: true
---

# Codex Code Review

Run an independent code review using OpenAI Codex, then critically evaluate and apply warranted findings.

## Prerequisites

Both the codex-plugin-cc and the global Codex CLI must be installed.

Plugin — if `/codex:review` is not available:
```
/plugin marketplace add openai/codex-plugin-cc
/plugin install codex@openai-codex
/reload-plugins
/codex:setup
```

Codex CLI — if the companion script reports "Codex CLI is not installed":
```bash
npm install -g @openai/codex
```

Auth: `!codex login` if not authenticated.

## Step 1: Determine what to review

Check what has changed:

```bash
git diff --stat HEAD
git diff --stat --staged
```

Decide the review scope:
- **Uncommitted changes** — use `--scope working-tree`
- **Feature branch vs main** — use `--base main`

## Step 2: Run Codex review

Find the companion script:
```bash
COMPANION=$(ls ~/.claude/plugins/cache/openai-codex/codex/*/scripts/codex-companion.mjs 2>/dev/null | sort -V | tail -1)
```

**For uncommitted changes:**
```bash
node "$COMPANION" review --scope working-tree --background
```

**For uncommitted changes with focus:**
```bash
FOCUS="focus on rule compilation ordering and fast-check correctness"
node "$COMPANION" adversarial-review --scope working-tree --background "$FOCUS"
```

**For feature branch vs main:**
```bash
node "$COMPANION" review --base main --background
```

**For feature branch with focus:**
```bash
FOCUS="<user argument>"
node "$COMPANION" adversarial-review --base main --background "$FOCUS"
```

**Poll for completion:**
```bash
TIMED_OUT=true
for i in $(seq 1 15); do
  sleep 20
  STATUS=$(node "$COMPANION" status 2>&1)
  if ! echo "$STATUS" | grep -qE "\| running \||\| queued \|"; then
    TIMED_OUT=false
    break
  fi
  echo "Still running... ($i)"
done

if [ "$TIMED_OUT" = "true" ]; then
  echo "Timed out — check manually: node \"$COMPANION\" status"
else
  node "$COMPANION" result
fi
```

The `result` command without a job ID returns the latest finished job.

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