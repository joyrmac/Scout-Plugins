# Parity check (Site Standard rule 20)

A site conversion changes how a site is built, never what visitors get. This
tool proves it: capture the site before, capture it after, compare.

```
node tools/parity/capture.js https://scoutraleigh.com parity/before
# ... the conversion: plugin features on, theme release, Site Sync ...
node tools/parity/capture.js https://scoutraleigh.com parity/after
node tools/parity/compare.js parity/before parity/after
```

- **What it captures, per page:** status and final address, title, meta
  description, canonical, robots, Open Graph and Twitter tags, hreflang, every
  JSON-LD block, the h1, and full-page screenshots at 390, 768 and 1280 px.
- **Which pages:** the site's sitemap (up to `--limit`, default 60), or a list
  with `--pages pages.txt` (one path per line). The home page is always in.
- **What counts as different:** any head tag, any change in the schema as data
  (key order, spacing and asset `?ver=` strings do not count), the h1, or more
  than `--pixels` percent of pixels at a width (default 0.5). Addresses are
  compared as paths, so staging can be checked against live.
- **The report** is written to `<after>/parity-report.md`, and the compare
  exits 1 when anything differs. Every difference is explained and approved
  before the site counts as converted (§11 step 9).
- Read-only: it loads pages like a visitor. Keep the capture folders out of the
  repo (they hold screenshots); attach the report to the conversion PR.
- Needs Node and Playwright with Chromium (`require('playwright')`).
