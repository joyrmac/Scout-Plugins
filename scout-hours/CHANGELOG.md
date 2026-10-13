# Scout Hours changelog

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
