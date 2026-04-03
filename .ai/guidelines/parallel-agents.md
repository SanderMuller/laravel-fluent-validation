## Parallel Agents for Independent Problems

When facing 2+ unrelated problems (e.g. different test files failing, multiple unrelated subsystems broken), dispatch one agent per independent problem domain using the `Task` tool. Multiple tool calls in a single message run concurrently.

### When to Use

- 2+ test files failing with different root causes
- Multiple subsystems broken independently
- Each problem can be understood without context from others
- No shared state between investigations

### When NOT to Use

- Failures are related (fixing one might fix others)
- Need to understand full system state first
- Agents would edit the same files
- **The user points to specific files** — use parallel `Read` calls instead. Agents are for discovery, not for reading known files.

### Agent Prompt Guidelines

Each agent should receive:
- **Specific scope:** one test file or subsystem, not "fix everything"
- **Context:** paste error messages, test names, relevant files
- **Constraints:** "do NOT modify the test", "only change files in src/"
- **Expected output:** "return summary of root cause and changes made"

After agents return, review each summary, verify fixes don't conflict, and run the full test suite.
