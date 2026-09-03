# Scout Forms Guard — Changelog

## 0.1.1 — 2026-09-03

Updates now install themselves.

- The shared updater opts this plugin into WordPress's automatic updates;
  define `SCOUT_DISABLE_AUTO_UPDATES` as `true` in `wp-config.php` to opt a
  site out. Other plugins' auto-update decisions are untouched.

## 0.1.0 — 2026-06-18

Initial scaffold.

- Honeypot, time trap, and HMAC-signed token injected into every Gravity Form.
- Validation via `gform_validation`; generic retry message via
  `gform_validation_message`.
- Filters: `scout_forms_guard_min_seconds`, `scout_forms_guard_max_seconds`,
  `scout_forms_guard_blocked`.
