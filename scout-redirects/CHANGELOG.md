# Scout Redirects changelog

## 0.1.0 (2026-10-13)

- First release. Redirect list with 301 and 302 redirects, hit counts and
  last-used dates, under Scout Build / Site Build → Redirects.
- Missing-page watch: logs page requests that end on the missing-page screen,
  leaves out crawlers and file probes, keeps a capped list, and turns an entry
  into a redirect in one click.
- Broken links on the site's own pages, from internal referrers, listed on the
  screen and reported in Site Health.
- Published pages that change address forward automatically, child pages
  included, with an Undo notice.
- CSV import and export.
