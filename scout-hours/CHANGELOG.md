# Scout Hours changelog

## 0.1.6 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.1.5 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 0.1.4 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.3 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.2 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.1 (2026-10-13)

- Ships the shared admin kit 1.3.0: the Scout menu bar is grouped into tabs (Home, Search, Website, Business, Reviews, Chat), and a long Scout sidebar shows one link per group.

## 0.1.0 (2026-10-13)

- First release. Weekly hours per day (closed, open and close times, an
  optional second range for a break) under Scout Build / Site Build → Hours.
- "Hours not confirmed yet": nothing reaches the site or schema until the
  owner confirms the hours. Editors see a short note where the hours will go.
- Holiday closures and special hours by date, with an optional note.
- `[scout_hours]` (with `show="status"` or `show="table"`) and
  `scout_hours_html()`: the hours table and a live "Open now", "Closed now"
  or "Closed today for (holiday)" line in the site's timezone, kept current
  in the browser so cached pages stay right.
- Optional notice bar on closure days, or from a week before.
- Schema: openingHoursSpecification and specialOpeningHoursSpecification,
  added to Scout Core's business node through `scout_schema_graph`, or a
  minimal node with the same @id when Scout Core is not active.
- Reminder to update Google: a dashboard notice a week before each closure.
