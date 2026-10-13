# Scout Case Results changelog

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
