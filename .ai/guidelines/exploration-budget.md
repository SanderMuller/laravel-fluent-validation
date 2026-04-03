## Exploration Budget

Scale research effort to task complexity. Simple tasks should start immediately; complex features deserve proper research and interactive planning.

### Simple Tasks (bug fixes, PR feedback, small changes)

- **Read 3-5 representative files max**, then start implementing
- **Use parallel reads** — never read files sequentially when you can read them at the same time
- **If unsure, implement and iterate** — let test failures guide your understanding rather than trying to fully understand the codebase upfront
- **Never spend an entire session exploring** — if you've read more than 10 files without writing any code, stop and start implementing

### Complex Features (new systems, multi-phase work, architectural changes)

Research is expected and encouraged, but it must be **structured and interactive**:

1. **Initial assessment**: Do a quick scan of the relevant area (a few file reads) to gauge complexity
2. **Check with the user**: If deep research is needed, tell the user what you want to investigate and why, and ask if they'd like you to do a thorough exploration first or prefer to guide you directly
3. **Research phase** (if approved): Explore the relevant parts of the codebase to understand existing patterns and constraints. Communicate what you're learning as you go.
4. **Ask questions**: Ask the user clarifying questions — don't silently assume answers to design decisions
5. **Plan, then implement**: Once you understand the scope and have answers, move to implementation without further open-ended exploration

Even for complex features, avoid **silent exploration spirals** — if you're reading files for several minutes without producing output or asking questions, something is wrong. Communicate what you're learning and what you need.

### General Rules

- **Always use parallel reads** when reading multiple files
- **Communicate during research** — tell the user what you're investigating and why
- **Research should produce questions or code**, never just silence
