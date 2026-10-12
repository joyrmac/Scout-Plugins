# Scout Analytics changelog

## 0.1.0 (2026-10-12)

- First release. Loads Google Tag Manager (or, without a container, a GA4 tag)
  on the live site only, with Google consent mode defaults set first.
- Cookie notice: count with opt out (default) or ask first. Global Privacy
  Control counts as a no. `[scout_privacy_choices]` reopens it.
- One click-reporting API for Scout features: `data-scout-track`,
  `data-scout-view` and `window.scoutTrack()`.
- Settings under Scout Build / Site Build → Analytics.
