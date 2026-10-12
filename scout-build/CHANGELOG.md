# Scout Build changelog

## 0.2.5 (2026-10-12)

- **Turn on works for a feature released in the last few hours.** The hub
  remembers GitHub's release list for six hours, so a brand-new release
  (Scout Analytics) showed "No release is published yet". When a feature has
  no release in the remembered list, the hub now asks GitHub again before
  saying so.

## 0.2.4 (2026-10-12)

- **Fix a robots.txt file that hides WordPress's.** A real robots.txt file on
  the server (Yoast's file editor leaves one) overrides WordPress and
  everything Scout adds to it. Site health now links to a new screen that
  shows the file next to what WordPress would send, and when no rule in the
  file would be lost, switches to WordPress's version with one click. The file
  is renamed and kept as a backup, and can be put back from the same screen.
  A file with rules of its own is left for a person to review.

## 0.2.3 (2026-10-12)

- **The hub's tabs are easier to spot.** Features, Requests and Health are
  now large buttons with a one-line status under each ("4 features on",
  "2 open requests", "AI search 7 of 9"), and Requests and Health also sit in
  the sidebar menu, so nobody has to find them inside the page.
- **A new look for every settings screen.** The plugin's screens now share
  one Scout design: a clear page header with the section name, settings
  grouped into rounded boxes, pill buttons, and a short sign-off footer with
  how to reach us. On client sites the header reads "Site Build" instead of
  "Scout". Nothing about how the settings work changed.

## 0.2.2 (2026-10-12)

- **Features opens first.** Inside a theme's Build menu, Features moves to the
  top, so clicking the section lands on the hub instead of the theme's setup
  tools.
- **Cleaner Features screen.** A summary bar (features on, ready to add, AI
  search score, plan) with the plan and version check under it, then the
  cards grouped into "On this site" and "Ready to turn on". Features the theme
  already covers drop to a short "Not used on this site" list.
- **Catalog:** Cards is marked not used on the Scout Media theme, which
  already makes the cards and the links page.

## 0.2.1 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 0.2.0 (2026-10-12)

- **Ready for AI search.** Site health gains a second scorecard: AI crawlers
  allowed, the /llms.txt guide, structured data, business details, direct
  answers on pages, fresh content, and real authors with bios. Each check reads
  WordPress itself, counts what the theme or Scout Core already does, and links
  operators to the fix. A meter shows "5 of 7 ready".
- **Direct answers reads the real pages.** Client themes print FAQs their own
  way, so the hub loads up to 15 main pages in the background (WP-Cron) and
  counts the ones carrying FAQPage schema. Cached 12 hours; "Check for new
  versions" re-runs it.
- **Your features** shows the same score as a chip, linked to the scorecard.
- **Catalog:** adds AI search (`scout-aeo`), included in every plan.

## 0.1.0 (2026-10-12)

First release: the Site Build hub (phase 1 of `docs/site-build-hub-spec.md`).

- **Features.** A card for every Scout feature, read from `catalog.json` in the
  Scout Plugins repo (cached 12 hours, with a bundled copy as the fallback).
  Each card shows on, off or not installed, the version, an update waiting,
  and whether it is included in the site's plan or an add-on.
- **Operators only.** Turning features on or off, going back a version and
  setting the plan need an operator: Sean and Joy by default, or the emails in
  `SCOUT_HUB_OPERATORS` in `wp-config.php`. Everyone else sees the list and an
  "Ask us to add this" button.
- **Turn on** installs the latest GitHub release if needed (and any feature it
  requires) and activates it. **Turn off** deactivates and deletes nothing.
- **Go back** reinstalls the previous release and pauses automatic updates for
  that feature until a newer release than the one left behind comes out.
- **Request a change.** A form that emails team@scoutraleigh.com (or
  `SCOUT_HUB_REQUEST_EMAIL`) with the page, timing and an optional file, and a
  list on the site with each request's status. A "Request a change" link in the
  admin bar fills in the page being viewed.
- **Site health.** WordPress and PHP versions, updates waiting, HTTPS, search
  engine visibility, and WordPress's own Site Health result.
- Scout branding: the menu is **Scout** with the cerulean bolt, and the screen
  carries the Scout mark and a "Built and looked after by Scout Media" sign-off
  with the team's email and phone. `SCOUT_HUB_BRAND` set to false switches to a
  neutral Site Build with no Scout mention (partner-side and white-label sites).
- Sits inside the site's existing Build menu (Scout Build, Recon Build, or
  Scout Core's Scout menu), and only adds a top-level Site Build section when
  there is none.
