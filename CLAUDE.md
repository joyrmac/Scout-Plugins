# Scout-Plugins: session notes

The shared Scout WordPress plugins, the theme kit and the release tooling. Client sites update
themselves from this repo's GitHub releases, so **it stays public**: platform code and docs
only, never client facts, install names, versions per site or access notes (those live in the
private Scout-Internal repo, `ops/fleet.md`).

- How to release: `RELEASING.md`. Bump the version, add the CHANGELOG entry, run the Release
  workflow. Never re-tag a version. Release scout-core first when a dependent plugin changes.
- How the plugins fit together: `README.md` and each plugin's own README.
- How every site uses them: the Site Standard, `Scout-Internal/ops/playbook/site-standard.md`.

## Scout Repo Standard (Joy, 2026-11-04)

Git, branches, pull requests, releases, READMEs, routines and skills follow the Scout Repo
Standard: `Scout-Media-Raleigh/Scout-Internal`, `ops/playbook/repo-standard.md`. **Read its §0
rules before starting work, and scan every branch first (rule 1).** `main` is the branch of
record; work on a branch and merge by pull request. Where this file and the standard disagree
on git, the standard wins.
