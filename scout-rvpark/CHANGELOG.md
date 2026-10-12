# Scout — North Crest RV Park — Changelog

## 0.1.2 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 0.1.1 — 2026-09-03

Updates now install themselves.

- The shared updater opts this plugin into WordPress's automatic updates;
  define `SCOUT_DISABLE_AUTO_UPDATES` as `true` in `wp-config.php` to opt a
  site out. Other plugins' auto-update decisions are untouched.

## 0.1.0 — 2026-06-18

Initial scaffold.

- Registers the `amenity` type (icon, summary, featured flag) through
  `scout_core_register_type()`.
- Registers the `site_type` type (hookups, nightly rate, monthly rate, max rig
  length).
- Serves as the reference implementation of the Scout Core companion pattern.
