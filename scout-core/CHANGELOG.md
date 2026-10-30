# Scout Core — Changelog

## 1.6.0 (2026-10-30)

Two spam switches, both on for every site unless someone turns them off under **Scout → Spam protection**.

- **Email addresses are hidden from spam bots.** Client sites printed their contact email in plain text (email links, visible text, the business details for search), and bots collected it, so clients got SEO spam. Scout Core now codes every address on the finished page before it is sent. Visitors see and click it as usual, and a page cache stores the coded copy. Inside the search data (JSON-LD) only the "@" is written as `\u0040`, so Google reads the same email. Scripts, styles, text boxes, form values and comments are left alone, and if anything goes wrong the page is sent unchanged. Filter `scout_core_email_guard` (true or false).
- **Comments and pingbacks are closed.** New comments and pingbacks on client sites mostly brought spam. With the switch on, pages and posts take no new comments or pingbacks, the `X-Pingback` header is gone, and the pingback XML-RPC methods are removed. Comments already saved stay in WordPress. Turn it off and the site works exactly as before. Filter `scout_core_close_comments` (true or false).
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.
- Test: `php tools/tests/email-guard.php`.

## 1.5.2 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 1.5.1 (2026-10-26)

- **Fix: no false "Page descriptions" warning on themes that write their own.** Scout Media and Recon set every page's description in their templates and never read the SEO box, so Recon's health check listed 16 pages that were fine and told Joy to fill a box that changes nothing. When the theme writes its own head, the check now reads "Your theme writes each page's description for search."

## 1.5.0 (2026-10-26)

Site Sync reaches four more places, so a theme update can carry them instead of one-time code in the theme. Joy asked why Sean's bio update needed theme code at all.

- **Business info, one field at a time.** A manifest can set a single Business info field (`scout_business`), leaving the others as typed.
- **Theme options, one key at a time.** A theme can let Site Sync set keys in its own array options (filter `scout_sync_option_keys`), such as one person's title on the business cards. A list item is found by its slug.
- **Team profiles, one field at a time.** A manifest can set one key inside an array meta value, such as a Team profile's role, and leave the rest of the profile alone.
- **Author bios.** A manifest can set an author's Biographical Info, matched by email.
- **A Core update re-runs the automatic sync,** so rows a new Core understands apply on the first admin load after it updates.
- Every new row has the same states, review, journal and undo as before. Spec: `docs/site-sync-spec.md`. 52 checks pass.

## 1.4.1 (2026-10-22)

Two Site Sync fixes found testing the Hair by Patrick pilot, before any theme ships a manifest.

- **Fix: automatic updates after adopting Site Sync.** A page that already matched the theme when a site adopted Site Sync got no starting record, so every later copy change read "Needs a decision" instead of updating on its own. Matching rows now get their record on the next sync pass (automatic or "Sync these now"), and a kept copy the repo has caught up with stops being kept.
- **Fix: a missing theme file can no longer blank a page.** When a manifest names a file the theme does not ship, that row is reported and left out, so neither the automatic run nor "Use the theme copy" writes an empty page.
- Integration test: 36 checks (was 30), including both cases.

## 1.4.0 (2026-10-22)

- **Site Sync.** One updating system for every Scout site, replacing each theme's own setup and sync screen over time. A theme lists the copy it bundles in a `scout-sync.json` file (page content, page fields, SEO titles and descriptions, shared settings, site title and tagline). Scout compares each item three ways: the theme copy, what WordPress holds, and what was there at the last sync.
  - **Untouched pages update on their own** after a theme update. Anything edited in WordPress is never overwritten without a person choosing to.
  - **Scout → Site Sync** shows what is up to date, what is ready to update, and what was changed in WordPress, side by side, with "Keep the WordPress copy" or "Use the theme copy" for each. A kept copy stays kept, even when a later theme moves on.
  - **Safe writes.** One sync at a time, an undo record saved before anything changes, every field checked again right before it is written, a page revision for content, and **Undo the last sync** that leaves anything edited since alone.
  - **Images** in a manifest go into the media library once and are matched by file, so a re-run never duplicates them.
  - A theme without the file gets the screen in status-only mode. Nothing changes on any site until its theme ships a manifest.
  - Health check row and an admin notice when items need a look. `wp scout sync status` and `wp scout sync run --dry-run` for the command line.
  - Companions can add items through the `scout_sync_manifest` filter and react through `scout_sync_applied`. Automatic runs can be turned off with `scout_sync_auto`.
  - Started from the reviewed content update built for a client site in September 2026, generalized so every site shares it. Spec: `docs/site-sync-spec.md`.

## 1.3.4 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 1.3.3 (2026-10-15)

- **Fix: review markup without stars.** Testimonials with no star rating were still marked up as `Review` items, and Google requires a rating on each one, so the Rich Results test showed them as invalid (Maravela's: 10 invalid review snippets). Only rated testimonials are marked up now; unrated ones still show on the page. The aggregate rating is unchanged (it only ever counted rated ones).

## 1.3.2 (2026-10-14)

- **Fix: two sets of share tags on themes that write their own.** Scout Media and Recon themes print their own title, description, canonical and Open Graph tags, and Scout Core printed a second set above them. Facebook and LinkedIn read the first `og:image`, so links showed the site icon instead of the page's share card. The SEO head now steps aside when the theme handles it (`scout_head()`, `recon_head()`, or `add_theme_support( 'scout-seo-head' )`). Filter `scout_core_seo_head` to force it either way.

## 1.3.1 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 1.3.0 (2026-10-13)

- **Every page carries its dates.** Single posts and pages get a `WebPage` node in the schema graph with `datePublished` and `dateModified`, linked to the site (`isPartOf`) and the business (`about`). Search engines and AI tools can see how fresh a page is. It steps aside when a companion already describes the page (Scout Team's `ProfilePage`). Filter `scout_schema_webpage` to false to turn it off.

## 1.2.7 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 1.2.6 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 1.2.5 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 1.2.4 (2026-10-13)

- **Fix: Business info and SEO now sit inside the theme's own Build menu.** On a site whose theme has a Scout Build or Recon Build menu, Scout Core made a second, separate "Scout" menu, so its screens were missing from the Search tab. They now join the theme's menu (the screen address stays `admin.php?page=scout`, so every link still works). Sites without a Build menu keep the Scout menu as before.

## 1.2.3 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 1.2.2 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.

## 1.2.1 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 1.2.0 (2026-10-12)

- **Fits the Scout Build hub.** With Scout Build installed, the Scout menu opens on Business info and SEO as two plain screens, and the search checks move into the hub's Site health. Without the hub, Search health stays as the first screen.
- **No more second tab bar.** The Dashboard / Business / SEO tabs are replaced by the shared section nav.
- **Same cards as the hub.** Search health uses the shared check cards, with pages missing a description listed by name.
- The Scout menu sits right under Dashboard with the cerulean bolt.
- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 1.1.4 (2026-10-12)

- **Page descriptions names the pages.** When pages or posts have no written
  description, the dashboard card now lists each one by title with a link
  straight to its editor (up to 10, then a link to all pages), instead of a
  count and a generic "Review pages" link.

## 1.1.3 (2026-10-12)

- **Sitemap guard.** If robots.txt ends up with no Sitemap line (for example
  an SEO plugin switched WordPress's sitemap off), Scout Core adds the one that
  works. A daily background check finds it, so serving robots.txt never waits.
- **One schema graph per page.** Once the business name is filled in, Scout's
  graph stands alone: Yoast, Rank Math, All in One SEO and SEOPress are told not
  to print their own JSON-LD, so Google never sees two businesses. Their titles
  and sitemaps are untouched. Filter `scout_schema_take_over` to false to keep
  theirs.

## 1.1.2 (2026-10-12)

- **A new look for every settings screen.** The plugin's screens now share
  one Scout design: a clear page header with the section name, settings
  grouped into rounded boxes, pill buttons, and a short sign-off footer with
  how to reach us. On client sites the header reads "Site Build" instead of
  "Scout". Nothing about how the settings work changed.

## 1.1.1 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 1.1.0
Pages become forms. Additive, nothing existing changes shape.

- **Fix: SEO box preview and counters.** Newer WordPress gives the SEO meta
  box heading the id `scout-seo-title`, the same id as the title field, so the
  live preview and character counts stopped updating. The fields now use
  `scout-seo-field-title` and `scout-seo-field-desc`.

- **Page fieldsets.** `scout_core_register_fields()` attaches a sectioned set
  of fields to the front page, to pages using a given template, to a slug, or
  to every post of a type. Each headline, paragraph, and picture on a designed
  page gets its own labelled field, grouped the way the page reads. Matched
  posts use the classic edit screen with the content editor hidden, so an
  editor changes words and images and cannot touch the layout. Values are
  plain post meta under `scout_<key>`, REST-exposed like every other field.
- **Image control.** `'control' => 'image'` stores an attachment ID and draws
  a preview with Choose image (media library, upload or pick) and Remove. Works
  in type meta boxes, fieldsets, and settings groups. `scout/field` bindings
  resolve it for `core/image` (`url`, `id`, `alt`).
- **Rich text and checkbox controls.** `richtext` is a small visual editor
  (bold, italic, link, lists) stored through `wp_kses_post`; `checkbox` stores
  `1` or nothing.
- **Settings groups.** `scout_core_register_settings_group()` adds a tab under
  the Scout menu for site-wide content (header, footer, shared bands, 404),
  one option per group, same controls.
- **Template helpers.** `scout_core_field()`, `scout_core_setting()`,
  `scout_core_image()`, `scout_core_image_id()`, `scout_core_image_url()`.
- **Dashboard checks filter.** `scout_core_dashboard_checks` lets a companion
  add its own cards to the health check.
- All controls now render through one class, `Scout_Core_Controls`, and the
  admin ships a small stylesheet and script (`assets/admin.css`, `assets/admin.js`)
  loaded only on edit screens and the Scout pages.

## 1.0.4
Fixes the fatal that took a site down on activation.

- `scout-core.php` still called `Scout_Core_Updater::init()`, the retired
  manifest updater whose file 1.0.1 deleted. Every 1.0.1+ install fataled on
  every page load ("Class Scout_Core_Updater not found"); no site had run
  1.0.1+ until 2026-09-03, when it took North Crest down on install. The stale
  call is removed. Lesson recorded: `php -l` cannot catch a call to a missing
  class, so a release needs an activation smoke test, not just a lint.

## 1.0.3
Updates now install themselves.

- The shared updater opts this plugin into WordPress's own automatic updates
  (`auto_update_plugin`), so a published release reaches every client site on
  the normal twice-daily check with nobody logging in to press Update.
- A site can opt out by defining `SCOUT_DISABLE_AUTO_UPDATES` as `true` in
  `wp-config.php`; updates then wait on the Plugins screen as before.
- Only Scout plugins are affected. Every other plugin's auto-update decision
  passes through untouched.

## 1.0.2
Deleting the plugin no longer erases the client's business identity or their
per-page SEO work.

- `uninstall.php` wiped `scout_business` (the whole NAP, hours, geo, and
  profiles) and every `_scout_seo_*` override on every page. Posts were spared,
  which is what the comment promised, but the identity and the SEO work were
  not, and losing them is invisible: the pages stay up and quietly stop saying
  what they were set to say.
- Both are now kept unless `purge_on_uninstall` is explicitly turned on. That
  matters because deleting a plugin is a normal way to troubleshoot, migrate, or
  re-install a broken copy, and none of those should cost a client anything.
- Fixed a stale transient key left over from the retired manifest updater.

## 1.0.1
Updates now come from the Scout Plugins releases instead of a hosted manifest.

- `Update URI:` is a real repository URL. It was the bare slug `scout-core`,
  which WordPress cannot turn into an update check, so the plugin could never
  have found an update at all.
- Replaced `includes/updater.php` (the `SCOUT_CORE_UPDATE_URL` manifest tracker,
  dormant because no manifest was ever hosted) with
  `includes/class-scout-plugin-updater.php`, which reads this repo's GitHub
  releases. Nothing to host, no credentials on the client site.
- README rewritten: the copy shipped in 1.0.0 still described the retired
  three-plugin split.

**Upgrading from 1.0.0 needs one manual upload.** A site running 1.0.0 has no
working update check, so it cannot pull this release by itself. Install 1.0.1
once by hand and every release after it arrives on the Plugins screen normally.

## 1.0.0
First production release, and a consolidation. **Scout Schema and Scout SEO are
now folded into Scout Core** as modules, so a client site installs one plugin
instead of three-in-order. (Scout Optimize and Scout Forms Guard stay separate.)

- **Merged the engine.** Schema (JSON-LD graph) and SEO (head tags, the per-page
  SEO box, XML-sitemap tuning) now ship inside Scout Core. No more
  install-in-this-order or cross-plugin `Requires Plugins`.
- **One Scout admin menu** replacing the buried "Settings → Scout Business":
  - **Dashboard** — a plain-language health check: business details, search
    listing, page descriptions, "visible to Google," and social sharing, each
    green or flagged with a one-click fix.
  - **Business** — the NAP + identity form (unchanged data, `scout_business`).
  - **SEO** — site-wide defaults (default share image, homepage description);
    per-page SEO still lives in the Scout SEO box.
- **Update tracker.** Self-hosted plugins can now update from wp-admin like any
  wp.org plugin, via a JSON manifest at `SCOUT_CORE_UPDATE_URL` (dormant until
  that URL is set, so it is safe to ship first). One fix reaches every client
  site with a click instead of a manual re-upload.
- **`uninstall.php`** cleans up Scout's options, the update cache, and the
  per-page SEO meta on delete. Content and types are left intact on purpose.
- Front-page description + default share-image now fall back to the SEO defaults.

## 0.1.0 — 2026-06-18

Initial scaffold.

- Type registry and the `scout_core_register_type()` public API.
- Universal post types: Testimonial and FAQ.
- Native field meta box (no ACF dependency); `register_post_meta` with REST
  exposure and per-control sanitization.
- Business identity options page (`scout_business`) and the `scout_core_business()`
  getter.
- Block Bindings sources `scout/field` and `scout/business` (WordPress 6.5+).
