---
name: pr-review-feedback
description: "Applies PR review feedback with critical evaluation. Activates when: applying review comments, addressing PR feedback, responding to code review, or when user mentions: review feedback, PR comments, apply feedback, address comments, reviewer feedback."
argument-hint: "[PR number]"
---

# Applying PR Review Feedback

A disciplined approach to addressing PR review comments: **evaluate first, apply selectively**.

## Core Principle

**Never blindly apply all feedback.** Instead:

1. Fetch the PR and its review comments
2. Filter out resolved conversations
3. Critically evaluate each piece of feedback
4. Apply feedback that improves the code
5. Skip or adapt feedback that doesn't fit

## When to Use This Skill

Use this skill when:
- Applying review comments on a PR
- Addressing reviewer feedback
- Responding to code review suggestions
- The user asks to "apply feedback" or "address comments"

## Workflow

### Phase 1: Gather Feedback

1. **Get PR details and unresolved review threads** via GraphQL:
   ```bash
   gh api graphql -f query='
   {
     repository(owner: "sandermuller", name: "laravel-fluent-validation") {
       pullRequest(number: <NUMBER>) {
         headRefName
         reviewThreads(first: 100) {
           nodes {
             id
             isResolved
             isOutdated
             comments(first: 10) {
               nodes {
                 body
                 url
                 author { login }
                 path
                 line
                 diffHunk
                 createdAt
               }
             }
           }
         }
       }
     }
   }' --jq '{
     headRefName: .data.repository.pullRequest.headRefName,
     threads: [.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved == false)]
   }'
   ```

2. **If the `threads` array is empty**, report "No unresolved review comments" and stop.

3. **Switch to the PR branch**
   - Extract branch name from `headRefName`
   - `git checkout <branch-name> && git pull origin <branch-name>`

### Phase 2: Evaluate Each Comment

**Handle outdated threads carefully:**
- If `isOutdated: true`, use `diffHunk`, `path`, and the current file contents to understand how the code changed
- Decide whether the feedback is now obsolete or still applicable

For each comment, ask yourself:

| Consider                                    | Action                                   |
|---------------------------------------------|------------------------------------------|
| Does it improve code quality?               | Apply it                                 |
| Does it follow project conventions?         | Apply it                                 |
| Is it a subjective preference?              | Consider context                         |
| Does it contradict project guidelines?      | Skip or discuss                          |
| Is it from an automated reviewer (Copilot)? | Evaluate critically - these can be wrong |

#### Common Bot False Positives

Be skeptical of automated feedback suggesting:
- **"Dead code"** — May be intentionally unused for now
- **Generic security warnings** — Verify whether a real vulnerability exists
- **"Missing type hints"** — Check if the project already has strict PHPStan rules covering this

### Phase 3: Apply Changes

For each piece of valid feedback:

1. **Read the relevant file** to understand context
2. **Make the change** following project conventions
3. **Run code style checks** — `vendor/bin/pint --dirty --format agent`

### Phase 4: Verify Quality

After applying feedback, use the `backend-quality` skill (Tier 1: Pint + related tests).

### Phase 5: Commit and Push

1. **Stage changes**: `git add <specific-files>`
2. **Commit with descriptive message**:
   ```
   Apply PR review feedback

   - <change 1>
   - <change 2>
   ```
3. **Push to the branch**: `git push origin <branch-name>`

### Phase 6: Reply to Review Threads

After committing and pushing, reply to each thread and resolve it:

```bash
# Reply to the thread
gh api graphql -f query='
mutation($threadId: ID!, $body: String!) {
  addPullRequestReviewThreadReply(input: { pullRequestReviewThreadId: $threadId, body: $body }) {
    comment { url }
  }
}' -f threadId="<THREAD_ID>" -f body="<REPLY>"

# Resolve the thread
gh api graphql -f query='
mutation($threadId: ID!) {
  resolveReviewThread(input: { threadId: $threadId }) {
    thread { id }
  }
}' -f threadId="<THREAD_ID>"
```

**Reply guidelines:**
- **Applied feedback**: "Fixed as suggested." or a brief note on what was changed
- **Skipped feedback**: Brief explanation of why
- **Discussion needed**: Ask a clarifying question — present planned reply to the user first

## Response Template

```markdown
## Applied Feedback (replied & resolved)

1. **[File]**: [What was changed]

## Skipped Feedback (replied & resolved)

1. **[File]**: [Comment summary]
   - Reason: [Why it was skipped]

## Discussion Needed (awaiting your input)

1. **[Topic]**: [Comment summary]
   - Planned reply: "[Draft reply text]"
```
