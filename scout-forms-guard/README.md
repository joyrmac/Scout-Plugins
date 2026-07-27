# Scout Forms Guard

In-house spam protection for Gravity Forms. No reCAPTCHA, no Akismet, no
third-party calls. Three layers, all invisible to real visitors:

1. **Honeypot** — a hidden field humans never see. Bots fill it; we reject those.
2. **Time trap** — rejects anything submitted faster than 3 seconds (a bot) or
   later than a day (a stale/replayed page).
3. **Signed token** — a hidden field signed with the site's secret key, so a
   forged or copied submission fails the check.

Requires **Gravity Forms** (the one third-party plugin Scout keeps). Without it,
this plugin does nothing.

## Filters

- `scout_forms_guard_min_seconds` (default 3)
- `scout_forms_guard_max_seconds` (default 1 day)
- `scout_forms_guard_blocked` — action fired when a submission is blocked (log it
  if you want).

## Note on caching

The timestamp is signed, so a cached form stays valid for up to a day. The
honeypot and signature work regardless of caching.
