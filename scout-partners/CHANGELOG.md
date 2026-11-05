# Scout Partners changelog

## 0.1.9 (2026-11-05)

- **README lists what this feature needs from the theme.** The tokens it reads, the calls, theme supports and filters a theme adds, and the pages it expects, in one "What this feature needs from the theme" section (Site Standard rule 4, status item F9). No code changed.

## 0.1.8 (2026-11-05)

- **Shared admin kit 1.11.0.** Plain menu names: To do, Business, People, Site features, Search, Legal pages and Site status (labels only, the group keys are the same). Ligatures on where the font has them, and one shared copy of the menu finder.
- **Plainer labels.** Overview is now Home in the Partners menu, and the browser tab reads Partners instead of Scout Partners. The Home screen keeps "Your partners" as its heading. The settings screen is now called Settings, to match its menu item. Page addresses and saved settings are unchanged.
- **Shorter help text** on the click count and the placements box.

## 0.1.7 (2026-11-05)

- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.1.6 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).

## 0.1.5 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.1.4 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.1.3 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.1.2 (2026-10-30)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.1.1 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 0.1.0 (2026-10-23)

- First release. Partners with a logo, website, one line, level (the owner's own list, highest first), the places they appear, optional dates, and regions when Scout Events is active. `[scout_partners]` grouped by level, with `level`, `placement`, `region`, `title` and `layout="grid|rail|logos"`. Partners can show on each Scout Events event page, from the event's region and the national ones. Every link counts a click per month on the way to the partner (bots left out, no visitor details kept), shown on the Overview and each partner. Editors and administrators manage partners; Settings is for administrators.
