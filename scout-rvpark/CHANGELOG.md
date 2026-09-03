# Scout — North Crest RV Park — Changelog

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
