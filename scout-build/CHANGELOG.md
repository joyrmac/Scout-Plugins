# Scout Build changelog

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
