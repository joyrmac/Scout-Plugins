# Scout Build changelog

## 0.3.10 (2026-10-13)

- **Scout in the toolbar.** The black bar at the top of every page shows a cerulean bolt and "Scout" ("Site Build" on unbranded sites). It opens Features, and its menu holds Site health and Request a change, which used to sit alone in the bar.

## 0.3.9 (2026-10-13)

- **Plans set.** Every site gets the basics (SEO, AI search, images, forms, analytics, call buttons, redirects, hours, accessibility, team credentials). Reviews come with The Partnership. Chat, business cards and case results are add-ons on any plan, and The Whole System includes every add-on. Feature cards show the right label for each. Turning features on or off is unchanged.

## 0.3.8 (2026-10-13)

- **Fix: sites showed "Up to date" when they were not.** Version checks used GitHub's API, which allows 60 calls an hour per server address; shared hosts like WP Engine run out, the check failed quietly, and the Features screen still said everything was up to date (North Crest stayed on Reviews 0.4.13 after 0.5.0 shipped). Sites now read one version list the Release workflow publishes as a plain download, with the API only as a fallback. If both fail, the Features screen says it could not reach GitHub instead of "Everything is up to date".

## 0.3.7 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.3.6 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.3.5 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.3.4 (2026-10-13)

- **"Up to date" stays current.** When the Features screen opens and the last version check is over an hour old, a fresh check runs in the background (WordPress cron), so a release from earlier today shows as "Update ready" on the next look. The "Check for new versions" button still checks right away.
- **Features keep themselves current.** Turning a feature on now also ticks its auto-update box. A one-time sweep ticks it for every Scout feature already on (and Scout Build itself), which fixes sites where Accessibility, Call buttons, Hours, Redirects and Team were installed with auto-updates off. Sites that block auto-updates are left alone.

## 0.3.3 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.3.2 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.
- **Your features, Site health and Request a change now use the screen name as the heading,** with the site name above it, so the title fits on a phone.

## 0.3.1 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 0.3.0 (2026-10-12)

- **Uses the shared frame.** Features, Site health and Request a change open in the same header, section nav and sign-off as every other Scout screen. The nav pills carry live hints (features on, AI search score, open requests).
- **One menu order everywhere:** Features, Site health, Business info, SEO, AI search, Image optimizer, then the rest, with Request a change last.
- **Site health shows the search listing checks** from Business info and SEO (page descriptions, visible to Google, social sharing), so there is one health screen.
- **Every card says whether it is current.** A green "Up to date" mark, or "Update ready: vX" with an **Update now** button that runs WordPress's own updater. Next to Check for new versions: "Everything is up to date" or how many updates are waiting, and when the site last checked.
- **Settings button on every feature that is on** and has a screen.
- **Fix:** the Business details fix link pointed at a page that no longer exists.
- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 0.2.6 (2026-10-12)

- **"Check for new versions" finds a release published minutes ago.** It
  now also clears each Scout plugin's own saved release check (kept for six
  hours) and WordPress's update list (kept for twelve), then checks again.
  Before, a new release could take hours to show as "Update ready".

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
