# Scout Case Results changelog

## 0.1.6 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 0.1.5 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.4 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.3 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.2 (2026-10-13)

- **One Scout section in the sidebar.** The all case results list moved from its own sidebar item into the site's Build menu (Business tab), and the Build menu stays open while editing an entry. Sites without a Build menu keep the old sidebar item.

## 0.1.1 (2026-10-13)

- Ships the shared admin kit 1.3.0: the Scout menu bar is grouped into tabs (Home, Search, Website, Business, Reviews, Chat), and a long Scout sidebar shows one link per group.

## 0.1.0 (2026-10-13)

- First release. A Case results list for a law firm's results: headline,
  practice area, result type (verdict, settlement or other), an optional
  amount, a short description and the date.
- Every result needs the firm's approval. "Approved by the firm" plus the
  approver's name and date; without them a result saves for review and
  cannot go live, whichever way it is saved.
- One disclaimer per site, starting from North Carolina, Virginia, Florida or
  general wording, editable. It prints beside every result and cannot be
  switched off for one result.
- `[scout_results]` grid with practice-area filters that work with or
  without JavaScript, and `[scout_result id=""]` for one result.
- The edit screen previews the result from the same headline field the site
  shows, and flags wording that can read as a promise or a ranking.
- No structured data. Settings under Scout Build / Site Build, Case results.
