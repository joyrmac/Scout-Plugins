# Scout Core — Changelog

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
