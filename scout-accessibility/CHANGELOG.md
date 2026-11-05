# Scout Accessibility changelog

## 0.1.19 (2026-11-05)

- **Colors and fonts from DESIGN.md (Site Standard rule 3):** the statement's contact buttons and "last reviewed" line now read the theme's DESIGN.md accent, pill corner and muted text (from `design-tokens.css`) when the theme does not set `--scout-a11y-*`. Sites that set them, or set neither, look exactly the same.
- **Accessibility checks open with one status line** ("1 check to fix. 2 of 4 launch checks reviewed.") instead of two status cards. The Fix pill and icon use the kit's warning tokens.
- **Footer link.** The three cards (menu, page, every page) are three rows in one box. Shorter lede and help.

## 0.1.18 (2026-11-05)

- **Shared admin kit 1.11.0.** Plain menu names: To do, Business, People, Site features, Search, Legal pages and Site status (labels only, the group keys are the same). Ligatures on where the font has them, and one shared copy of the menu finder.
- **Email and phone read Business info live.** The Email for barrier reports and Phone fields on the Accessibility statement screen are optional overrides. When one is blank, the statement uses Business info's email or phone at the moment the page shows. A value already saved here keeps showing exactly as before. Both hints now read "Leave blank to use Business info." and the placeholders show what Business info holds.
- **Plainer label.** The footer box "Use the shortcode" is now "Add it to a page". The shortcode is the same.
- **Shorter, plainer help text.** Notes on both screens are one short sentence each. "Print it automatically" is now "Add it to every page". The statement visitors read is unchanged.

## 0.1.17 (2026-11-05)

- **The Accessibility statement screen opens with one status line.** Published with a View the page button, or not created yet with a link to the statement. It replaces the single status card.
- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.1.16 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).
- **Two screens, each in its own group (Joy, 2026-11-04).** The accessibility statement and the footer link stay at `scout-accessibility`, under Policies. The weekly checks and the at-launch checklist move to their own screen, `scout-accessibility-checks`, under Site health, and carry the "to fix" count. Each screen links to the other with a "Looking for" line. A failing Statement check links to the statement screen.

## 0.1.15 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.1.14 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.1.13 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.1.12 (2026-10-30)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.1.11 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 0.1.10 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.1.9 (2026-10-14)

- **Fix: "Published, but no link to it was found" when the footer link is there.** The check loaded the site's own home page to look for the link, and some hosts and firewalls block a site from loading itself (scoutraleigh.com showed the warning with the link in its footer). Now, whenever `[scout_accessibility_link]` prints the link on a visitor's page, the plugin notes the day, and the check counts the link as found for 30 days. Open any page of the site once after updating, then run the checks.

## 0.1.8 (2026-10-14)

- **Fix: "a second main heading (H1)" on every page of some block themes.** The heading check assumed the theme prints each page title as the H1. Block themes that put the H1 in the page content (Hair by Patrick does) were flagged on 16 pages. The check now reads the page's template: when the template prints no H1, the content's first H1 is the page title and counts as correct. A second H1 is still flagged.

## 0.1.7 (2026-10-14)

- **A hand-written Accessibility page is kept word for word.** If a site already has its own page at /accessibility/ (Hair by Patrick has one with his text-first contact and his in-person notes), saving the statement now uses that page as is: it's linked and checked, and our statement is never added on top. The screen says so.

## 0.1.6 (2026-10-14)

- **The Accessibility page gets a search description.** Site health flagged it as the one page with no description. New pages get one when they're made, and an existing page gets one the first time an admin opens the dashboard after the update (only if it has none).

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
