# Scout Accessibility changelog

## 0.1.5 (2026-10-13)

- **Ticking a launch check fills in today's date and your name right away**, and the label reads "Done once saved", so the row no longer looks half done before you save.
- **Fix: missed updates on shared hosts.** Version checks read the version list the Release workflow publishes instead of GitHub's rate-limited API.

## 0.1.4 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.3 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.2 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.1 (2026-10-13)

- Ships the shared admin kit 1.3.0: the Scout menu bar is grouped into tabs (Home, Search, Website, Business, Reviews, Chat), and a long Scout sidebar shows one link per group.

## 0.1.0 (2026-10-13)

- First release. Build → Accessibility (Settings → Accessibility without a
  Build section).
- Statement builder: business name from Scout Core's business info (or the
  site title), contact email and phone, reply time, an optional "another
  format" line (off by default), and a last-reviewed date. Saving creates or
  updates the Accessibility page with `[scout_accessibility_statement]`.
- The statement aims for WCAG 2.2 AA, explains how to report a barrier, and
  never claims full compliance.
- Footer link helper: add the page to a menu, use `[scout_accessibility_link]`,
  or print a small link below the theme footer.
- Weekly checks (WP-Cron): images with no alt attribute, skipped heading
  levels, form fields with no label, and whether the statement page exists,
  is linked and was reviewed in the last year. "Run the checks now" button.
- At-launch checklist for color contrast and keyboard use, ticked by hand
  with a date and a name.
- No overlay widget and no script on the live site.
