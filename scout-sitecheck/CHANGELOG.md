# Scout Site Check changelog

## Unreleased (2026-11-05)

- **Status line is one sentence and one button (Joy, 2026-11-05).** "Not made yet." with Make the page as the one primary button, or Edit the page once it exists, and quiet View and Check requests links. Waiting checks show in the headline. Whether the Google speed key is saved moved into More options as its own small status line; the status line only mentions it when the page is live and checks are failing for want of it.
- **One primary button.** Save changes is outlined while Make the page is showing.
- **Check requests pills show their color.** Running and Waiting for review are calm blue, Sent is green, Not sent and Couldn't reach the site are red, and "Not yet" followed up is a calm grey pill.
- **No names on client sites.** The Who follows up placeholder and example use a neutral name instead of Sean. The report box puts the address, its status, the score and the headline on separate lines instead of joining them with dots.

## 0.2.11 (2026-11-05)

- **Privacy tools reach check requests.** WordPress's Export and Erase Personal Data tools now find a person's check requests by email. Erasing deletes them and clears the count that limits checks per email.

## 0.2.10 (2026-11-05)

- **Shared admin kit 1.11.0.** Plain menu names: To do, Business, People, Site features, Search, Legal pages and Site status (labels only, the group keys are the same). Ligatures on where the font has them, and one shared copy of the menu finder.
- **The Book a call button is never empty.** When Scout Booking has no booking page (or it was removed), it goes to the Contact page.
- **Site check emails can use the shared notice address.** "Email new checks to" is now an optional override. When it is blank, the team's copy and the report's reply address use Send notices to in Business info (or the site email when Scout Core is older). An address already saved here keeps working.
- **Plainer names.** The settings screen and its menu item are both "Free site check" (the menu said "Site check settings"), and the list of requests is "Check requests" (was "Site checks"). Same addresses and settings.
- **Shorter, plainer help text** (Sean's voice, admin kit DESIGN.md 2a). The screen's opening line, the speed key status and each field's help are one short sentence. The visitor's page, form and report wording are unchanged.

## 0.2.9 (2026-11-05)

- **The Free site check screen opens with one status line.** The three status cards are now one line: the page (live, draft or not made), the Google speed key, checks waiting for follow-up, and the page button. The speed key and the shortcode note move into a closed More options box. One Save changes button. Same fields and option.
- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.2.8 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).
- **Safe with an older admin kit.** If a plugin still on kit 1.7.0 loads first, Site check settings file under Your business instead of asking for a group that kit doesn't have.
- **Site checks file under Inbox, and Site check settings under Your website.** Requests from visitors now sit with everything else waiting on a person.

## 0.2.7 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.2.6 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.2.5 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.2.4 (2026-10-30)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.2.3 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1. The settings screen's tab now reads "Site check settings", so it is never confused with the list beside it.

## 0.2.2 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.2.1 (2026-10-14)

- **A privacy link under the button.** The line under Check my site now links to the site's privacy policy ("How we use your details"), so people see how their name and email are used before they send them. It shows when WordPress has a privacy page set (Settings, Privacy).

## 0.2.0 (2026-10-14)

- **A landing-page layout.** `[scout_site_check layout="hero"]` puts the form and the sample report side by side for the top of a page. The default stays stacked.
- **The sample report shows all eight checks,** with a big score, a headline and one colored bar per check. When it scrolls into view the rows resolve one at a time, once (it stays still for visitors who turn motion off).
- **Calmer live run.** A progress bar and one dot per step replace the spinning circles, and the result shows the score as a big numeral instead of a cerulean ring.
- Inputs use the 8px corner from the Scout design system, buttons darken on hover with `--accent-fill-hover`, and focus rings use the brand cerulean.

## 0.1.1 (2026-10-13)

- **Fix: a passing speed line contradicted itself.** A site with a speed score of 90 or more but main content slower than 2.5 seconds read "shows in 3.2 seconds. Google's goal is under 2.5 seconds" next to a green check. It now gives the score and says the time is close to the goal.

## 0.1.0 (2026-10-13)

- **First release.** A free website check visitors run from the site with `[scout_site_check]`. It checks eight things: speed on phones (Google's speed tool), the security lock, page titles, business info for Google, phone layout, AI crawlers (robots.txt plus a test visit as each bot), the sitemap and photo sizes.
- **Results on the page, report by email.** The visitor watches the four steps finish, sees the score and every row, and gets the full report by email with a "Book a call" button. A copy goes to the team with the visitor's contact details.
- **Site checks inbox** under Scout → Business, with the report, Send report, Reply by email and a Followed up box. The sidebar counts checks nobody has followed up on.
- **Review before sending** (off by default) holds each report until someone clicks Send report.
- **Built to be safe to leave on a public page:** a honeypot, limits per visitor, per email and per day, and every request goes through WordPress's safe HTTP calls, which refuse private and local addresses.
