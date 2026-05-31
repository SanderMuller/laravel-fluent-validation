# Release Benchmark Automation

Repo-specific addition to the generic `release-automation` guideline
(shipped by `package-boost-php`). Covers only the benchmark-table
injection unique to this package — the CHANGELOG CI flow, release-notes
file, tag/title, and agent-handoff conventions all come from the
vendor guideline.

## Benchmark table in the release body is CI-injected

`.github/workflows/release-benchmark.yml` appends the latest benchmark
table between the `<!-- benchmark-start -->` / `<!-- benchmark-end -->`
markers in the release body after publish.

- Do **not** paste benchmark numbers into the release body by hand.
- Write the narrative above the markers and let CI fill in the table.
- The markers must be present in `internal/release-notes-<version>.md`
  for the injection to land.
