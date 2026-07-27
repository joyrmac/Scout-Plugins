# Scout Core — Changelog

## 0.1.0 — 2026-06-18

Initial scaffold.

- Type registry and the `scout_core_register_type()` public API.
- Universal post types: Testimonial and FAQ.
- Native field meta box (no ACF dependency); `register_post_meta` with REST
  exposure and per-control sanitization.
- Business identity options page (`scout_business`) and the `scout_core_business()`
  getter.
- Block Bindings sources `scout/field` and `scout/business` (WordPress 6.5+).
