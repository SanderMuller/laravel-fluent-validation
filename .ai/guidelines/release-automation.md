# Release Automation

## CHANGELOG.md is updated automatically — do NOT edit by hand for releases

`CHANGELOG.md` is kept in sync with GitHub releases by `.github/workflows/update-changelog.yml`. When a release is published (not just drafted), the workflow uses `stefanzweifel/changelog-updater-action` to prepend the release body to `CHANGELOG.md` and commits the update back to `main`.

This means:

- **Do not** add changelog entries manually when preparing a release. The release body (drafted in `internal/release-notes-<version>.md` and pasted into the GitHub release) becomes the changelog entry automatically.
- **Do not** include a changelog diff in the release PR — the post-release commit comes from CI.
- If the changelog needs a fix *after* a release, edit `CHANGELOG.md` directly and commit — but this is unusual and only for typos or formatting issues in the auto-generated entry.

## Benchmark table in release body is updated automatically

`.github/workflows/release-benchmark.yml` appends the latest benchmark table between the `<!-- benchmark-start -->` / `<!-- benchmark-end -->` markers in the release body after publish. Do not paste benchmark numbers manually into the release body with those markers — write the narrative above and let CI fill in the table.

## Release workflow (summary)

1. Draft release notes in `internal/release-notes-<version>.md`
2. Commit and push code + notes file to `main`
3. Tag and create the GitHub release with the release-notes file as the body
4. CI automatically:
   - Appends the benchmark table to the release body
   - Prepends the release body to `CHANGELOG.md` and commits it back to `main`

No manual `CHANGELOG.md` edits are part of the release PR.
