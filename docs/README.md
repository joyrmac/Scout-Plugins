# Docs

Specs for the Scout Build hub and its Site log, plus the history files each site imports into
that log. Each plugin's own README stays in its plugin folder.

## What's here
- `site-build-hub-spec.md`: the spec for the Site Build hub, the one dashboard screen that shows
  and switches a site's Scout features. Drafted 2026-10-12.
- `site-log-spec.md`: the spec for the Site log, one line on the site for every change made to it,
  written by the plugins that make the change. Approved 2026-11-04.
- `site-log-import/`: one JSON history file per Scout WordPress site. Scout Build's one-time
  import reads them and skips any line already in the log (`import_id`).
  `tools/tests/site-log.php` checks every file here.

## Who owns it
Joy and Sean at Scout Media.

## Boss doc
`README.md` at the repo root for how the plugins fit together, `RELEASING.md` for releases, and
`scout-build/README.md` for how the hub and the import work today. Where a spec here and the
shipped plugin disagree, the plugin's README and CHANGELOG describe what is true.

Keep this README current: a change to this folder's structure updates this file in the same pull
request (Scout Repo Standard rule 25).
