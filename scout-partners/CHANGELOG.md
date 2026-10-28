# Scout Partners changelog

## 0.1.1 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 0.1.0 (2026-10-23)

- First release. Partners with a logo, website, one line, level (the owner's own list, highest first), the places they appear, optional dates, and regions when Scout Events is active. `[scout_partners]` grouped by level, with `level`, `placement`, `region`, `title` and `layout="grid|rail|logos"`. Partners can show on each Scout Events event page, from the event's region and the national ones. Every link counts a click per month on the way to the partner (bots left out, no visitor details kept), shown on the Overview and each partner. Editors and administrators manage partners; Settings is for administrators.
