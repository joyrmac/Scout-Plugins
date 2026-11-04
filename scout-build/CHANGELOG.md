# Scout Build changelog

## 0.12.0 (2026-11-04)

- **The redesigned Scout menu (dashboard redesign, Joy 2026-11-04).** Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools. Every screen keeps its address; only where it is filed changes. The full map and the rules for filing new screens are in `docs/dashboard-map.md`.
- **Home replaces Overview** as the first screen's name, in the sidebar and on the screen. Your site stays under Home for clients.
- **Who sees what.** Operators see everything, including Scout tools (Features, Updates, Site log, Email, Chat settings, Customer list, Site Sync, the Change log). Client owners see everything but Scout tools. Staff (anyone who cannot change site settings) see Home, Inbox and Your business.
- **Greyed "Turn on" items are for operators only.** Clients only see groups that have something in them.
- **The sidebar follows the menu order** even when the Scout menu is short enough not to be grouped.
- **Today on Home.** "Needs you" becomes a numbered Today list: each item says about how long it takes and has one button, the first is marked Start here, and "1 of 4 done" counts what left the list earlier today. "Not today" hides an item until tomorrow. With nothing left, Home says "Nothing needs you today." Items come from the Inbox rows, failing health checks and a site hidden from Google, and any plugin can add one with the `scout_hub_today` filter. Staff only see items they can act on.
- **The first-week setup list.** Site owners see five steps above Today until they're done: business name, address and phone, weekly hours, logo and site icon, the team, and the Google Business Profile link. Steps for features the site doesn't run are left out. Operators can hide the list on a site. Filter: `scout_hub_setup_steps`.
- **Shared admin kit 1.8.0** files each screen into the new groups, with the test in `tools/tests/admin-kit-groups.php` updated to match.

## 0.11.1 (2026-11-04)

- **Release rings (Site Standard rule 19).** A Scout plugin release now installs itself on scoutraleigh.com and staging copies first (ring 1), on Recon a day later (ring 2) and on client sites two days after it is out (ring 3). Until a site's turn the automatic update waits; Build, Updates says on each plugin's row when it installs, and Update now still works. The ring comes from `SCOUT_HUB_RING` in wp-config.php, the setting on the Updates screen, or the address. A release whose date the version list does not carry is never held, so nothing changes until the Release workflow publishes dates (this release adds them).
- **Pause switch.** Build, Updates, "Pause automatic updates for 7 days" stops automatic Scout updates on one site; "Turn automatic updates back on" ends it early. Operators only.
- **Hold a release everywhere.** A `hold` entry in catalog.json (`{"slug": "scout-build", "version": "0.10.0", "note": "Fix coming"}`, or `"version": "*"`) stops that release's automatic update on every site that reads the catalog (within 12 hours, or at once with Check for new versions). The Updates screen shows the hold and its note.
- The version list (`releases.json` on the feed release) now carries each release's `published_at`.
- Test: `php tools/tests/release-rings.php <wordpress>` (17 checks).

## 0.11.0 (2026-11-04)

- **Your site: the client's view of the Site log.** Anyone who is not a Scout operator opens the same screen as **Scout → Your site**: only lines marked "Client sees", in their client wording, with no versions, kinds or internal detail. Four parts: **Waiting on you** (anything Scout needs their OK on, with an Approve button), **This month**, **Your requests** (open ones, with the Request a change button) and **Coming up** (planned lines), then **Earlier this year**. It files under Overview for them (Build stays hidden), and the Overview's "With Scout" box links to it. Operators get a "See it as the client" link on the Site log. Step 3 of the Scout Client Hub.
- **Approve.** A line an operator marks "Waiting on you" and shows to the client carries an Approve button there. Approving marks it done with the approver's name and date, writes an approval line ("Approved by Patrick McGuire: …") the client also sees, and emails Scout. The Inbox counts "Waiting on your OK" with the other queues. "Ask for a change first" opens the request form with the line filled in. This replaces screenshotting a text into a decisions log.
- **The Scout box on the Dashboard** says how many updates the site had this month and how many things wait on the client's OK, with a link to Your site.

- **Hub keys made on the site, no wp-config edit.** Joy: "can't I edit wp-config in WordPress?" No, and WP Engine turns the file editors off, so the keys the Scout Client Hub needs no longer require SFTP. On Scout → Site log, operators press **Make keys**: the site makes a read key (for the Sheet) and a write key (for Claude), shows them once, and stores them encrypted the way the theme key is stored. A client administrator never sees them again. **Make new keys** replaces the pair; **Forget the keys** turns the endpoint off. `SCOUT_HUB_READ_TOKEN` and `SCOUT_HUB_WRITE_TOKEN` in wp-config.php still work and win over keys made on the screen. Making keys writes a setup line in the log.

## 0.10.0 (2026-11-04)

- **The Scout menu is built around who is looking (Site Standard §7, v1.2; Joy, 2026-11-04).** Seven items, the same words in the sidebar and on the screen: **Overview**, **Your business**, **Content**, **Search and AI**, **Site health**, **Inbox**, **Build**. Every existing screen keeps its address; only where it is filed and what the first screen is change. Shared admin kit 1.7.0, with a test (`tools/tests/admin-kit-groups.php`) so the filing never drifts from the map.
- **Overview, the first screen for everyone.** One line that says whether the site is all right (from Site health), "Needs you" with the Inbox counts, "With Scout" (updates waiting, the theme, the last three Site log lines), the four things a client comes here to do (change a page, write a post, update hours, ask for a review), and one line to ask for more: "Want something added or changed? Ask Scout."
- **Inbox: everything waiting on a person, one count.** Change requests, unanswered chat questions and new reviews, each with its count and a button, plus Ask for reviews. The sidebar shows the total as a bubble. Other plugins add a queue with the `scout_hub_inbox` filter.
- **Build is for operators.** Features, Updates and the Site log file under Build, and the admin kit hides the group from everyone who is not an operator (`SCOUT_HUB_OPERATORS`), so a client never sees a turn-on button. The screens stay reachable by address for admins who know them; every action on them still checks for an operator.
- **Your business holds every fact once.** Business info, Call buttons, Hours, Team, Case results, Reviews and Review settings, Cards & Links, Booking, Events, Members, Partners and Newsletter file together. Content holds Site Sync, Redirects, Image optimizer and Writing. Search and AI holds SEO, AI search, Analytics, Accessibility, Spam protection and Site checks. Email, Chat settings, Customer list, the Change log and a theme's own screen sit under Build.

## 0.9.1 (2026-11-04)

- **Site health: "Design contrast".** With Scout Core 1.6.3 or newer and a theme that has a DESIGN.md, Site health shows the lowest contrast ratio among the pairs the Site Standard requires (button text on the accent fill, text on the page and on cards) and names any pair under 4.5:1 (rule 10). Muted text under 4.5:1 is a warning, not a failure.
- **Brand color from DESIGN.md.** The Email screen's detected color (which the cookie notice and form emails use) now reads the theme's `accent_fill` from DESIGN.md before Scout Cards, Scout Reviews or theme.json. Sites without a DESIGN.md are unchanged.

## 0.9.0 (2026-11-04)

- **The Site log: one line for every change to the site, written by the plugins that make it.** New screen, Scout, Site log. A Scout plugin updating logs its version and the first line of its changelog; the theme updating logs its version and its changelog headline; a feature turned on, off or sent back logs who did it; Site Sync logs how many pages got new copy; the setup file logs what it filled in; a change request logs when it comes in and when it moves; the hidden-from-Google alert logs when it goes out and when the site is visible again. Each line carries a kind, a topic, a status (done, planned, waiting on you), the version, a measure (before and after) and whether the client sees it. Lines shown to the client carry their own plain wording; the screen flags the ones that have none yet. A done line never changes: only its client wording, measure and client flag can be edited. Operators add notes for work done by hand, filter by month, kind, topic and status, and export the view as CSV. This is step 1 of the Scout Client Hub (spec: `docs/site-log-spec.md`).
- **Import history.** One time per site, operators upload the site's file from `docs/site-log-import/` (the 43 rows of Scout's site-updates record as of 2026-11-02, split per site). Lines already in the log are skipped, so running it twice adds nothing.
- **A read endpoint for the Scout Client Hub.** `scout-build/v1/log`, `/requests` and `/site` answer a Bearer key set in `wp-config.php` (`SCOUT_HUB_READ_TOKEN`; `SCOUT_HUB_WRITE_TOKEN` can also `POST /log` note lines, which is how Claude logs work as it ships). `/site` returns the plan, WordPress, PHP, theme and every Scout plugin's version and update state, the Site health rows, the AI-ready count, open requests, review totals and spam counts. No visitor data, form entries, attachments or keys. 60 calls a minute per key; a call with the wrong key is noted in the log once an hour, with no address. Off until a key is set, and off on white-label sites unless `SCOUT_HUB_LOG_API` is true.
- **Requests can be created by other plugins.** `Scout_Hub_Requests::create()` saves a request and fires `scout_hub_request_opened`; Scout Chat 0.3.0 uses it so chat requests join the same list. New actions for the log: `scout_hub_feature_done`, `scout_hub_request_status`, `scout_hub_setup_done`, `scout_hub_watch_alert`, `scout_hub_watch_cleared`. The log also listens to Scout Core's `scout_sync_applied`.
- **Not the same as Scout Core's Change log.** Scout Core 1.7.0's Change log (under Updates) records what someone who is not Scout edits on the site, so the repo can catch up. The Site log records what Scout does for the client, and what the client asked for. A later release feeds the first into the second as a line the client can see.

## 0.8.0 (2026-11-04)

- **Scout → Updates: every update in one place.** A new Updates screen holds everything that brings a new version or new content onto the site (Site Standard rule 12): the Theme box (version, read-only key, Update the theme now), a Plugins table (every installed Scout plugin with its version, Up to date or Update ready, Updates paused, and Update now), Check for new versions, Fill from theme, Site Sync, Import posts (XML), Site settings, the Change log and the Recent changes log. The Features screen keeps the feature cards, the plan and the counts; the Theme box, the version line and the two buttons moved off it. Check for new versions and Fill from theme again now return to Updates. Site Sync and the Change log open their Scout Core screens; the cards say plainly when a tool is not on the site yet (no Scout Core, or Scout Core older than 1.7.0), and Site settings waits for its export tool (`scout_hub_site_settings_url` filter).
- **One menu named Scout on every site** (rule 11). When a theme still registers its own Build menu (scoutraleigh.com's "Scout Build", Recon's "Recon Build"), the hub relabels it Scout (Site Build on partner sites) so every dashboard reads the same until that theme's conversion drops the menu.
- **Every group shows on every site, even with nothing on** (rule 11). The seven groups (Home, Updates, Search, Website, Business, Reviews, Chat) always appear in the section nav and the sidebar. A group with nothing on is greyed with a Turn on hint and opens the Features screen with a note saying which group it came from. Shared admin kit 1.6.0.
- **The Updates group in the shared nav.** Site Sync and the Change log sit under Updates; Spam protection under Website; Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The "N on" hint stays on the Features entry only; Updates shows "N ready" when releases are waiting.

## 0.7.11 (2026-11-04)

- **Theme updates keep downloading after a repo moves or is renamed.** A theme whose Update URI still names the old owner or repo (`joyrmac/Andres-Borda---Golf-Carts`) gets its file address from GitHub under the new name (`Scout-Media-Raleigh/AndysGolfCarts`). The download check compared the two, skipped the key, and WordPress fetched the private file without it: "Download failed. Not Found." The check now also trusts the exact file address the release lookup returned, whatever owner and name it carries. Each theme picks up its new Update URI at its next release.
- **The feature catalog and Site health read from the new organization** (`Scout-Media-Raleigh/Scout-Plugins`).
- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.7.10 (2026-11-02)

- **Theme updates download when the repo name's capitals differ.** Maravela's theme says `Update URI: https://github.com/joyrmac/maravelas`, but GitHub hands back the file address as `joyrmac/Maravelas`. The download check compared the two letter for letter, so it skipped the key and WordPress fetched the private file without it: "Download failed. Not Found." The check now ignores capitals, the way GitHub does.

## 0.7.9 (2026-10-30)

- **"Test builds" no longer flags a client's own plugin.** On Andy's live site the row named his companion plugin (Scout — Andy's Golf Car Garage), which lives in his repo and never gets a Scout Plugins release. The row now looks only at plugins that update from this repo.

## 0.7.8 (2026-10-30)

- **Form emails in the site's own colors.** Gravity Forms notifications now arrive in the same card as the Email test: the site's color bar, its Site Icon, the site name, and the subject as the headline, with the form answers below. When the message has a phone number, it gets Call and Text buttons; when it has an email address, an Email back button that reads plain-text addresses too (the first one that is not the site's own). The headline leaves out the phone number, since it already has its Call button. Confirmations sent to the person who filled in the form get the card without those buttons. Light colors like Carolina blue get dark button text so it stays readable. Only HTML notifications, only while Scout Email is set up. Joy, on Andy's request email: "can these be branded?" Off switch: the `scout_mail_brand_forms` filter.
- **Other Scout plugins can use the site's brand color.** `Scout_Hub_Mail::brand_color()` returns the Email screen's pick or the detected color; Scout Analytics 0.1.15 colors the cookie notice's OK button with it.
- **Site health: "Hidden upload fields".** With Scout Forms Guard 0.3.0 or later, Site health lists any Gravity Forms File Upload field set to Hidden, with a link to fix it.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.
- **Site health: "Test builds".** Lists any active Scout plugin running a test build: a version with a label like `0.7.8-test.1`, or one newer than its newest release. Neither updates the normal way, and the row says which plugin and what release it waits for. It reads the release list the updater already keeps, so it adds no call to GitHub. On Andy's site a Scout Build test zip was replaced by the next release, and a Forms Guard test build had no release to update to. `tools/test-build.sh` builds labeled test zips (see RELEASING.md).
- **Scout hears when a live site is hidden from Google.** When the live site has "Discourage search engines" ticked in Settings, Reading, Scout Build emails team@scoutraleigh.com ("<Site name> is hidden from Google") as soon as the setting changes and from a daily check, at most once a week while it stays on, and shows administrators a notice. Staging addresses, non-production environments and local copies are left alone. `SCOUT_HUB_REQUEST_EMAIL` or the `scout_build_alert_email` filter changes the address. Andy's live site carried the setting over from staging for days and nobody at Scout knew.
- **Site health: "Form emails go to".** Lists where each active Gravity Forms form sends its emails: the addresses (with {admin_email} filled in), "the address in field 3", or "routing rules". On a live site it asks for a look when a form sends no email or emails only Scout. The `scout_build_agency_domains` filter sets which domains count as Scout's.
- **Site health: "Review totals".** With Scout Reviews 0.5.5 or later, shows how long ago the review counts were last updated, and asks for a look after 30 days. Andy's site said 17 Google reviews when Google showed 20. Sites that have not saved the totals since updating read "Update the totals once to start tracking."

## 0.7.7 (2026-10-28)

- **Site health, Search engines: "Kept out on staging".** A staging copy behind the host's password protection (its front page answers 401), or one the theme or a plugin already marks noindex, now reads as kept out of Google instead of asking for a password that is already on. The password probe is cached for an hour.
- **Features screen: no stray "01" on recommended cards.** The order number on a recommended card used the class of the numbered setup steps, so it picked up their counter. It has its own class now.

## 0.7.6 (2026-10-28)

- **Fix: Direct answers no longer counts password-protected pages.** A private partner page (WordPress page password) is read by no visitor and no AI tool, but it was checked as a main page and counted as one without answers. It is skipped like the legal pages. The count catches up at the next scan (within 12 hours).

## 0.7.5 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 0.7.4 (2026-10-27)

- **Site health flags a staging copy that search engines can index.** A staging site said "Search engines: Allowed, Google can find the site" with a green tick, which is the right answer for a live site and the wrong one for a copy that can end up in Google beside it. On a host staging address (WP Engine, Kinsta, Flywheel, Pantheon, Cloudways) or when WordPress's environment type is staging, development or local, it now reads "Allowed on staging" and points to the host's password protection, not the Search engine visibility box, since that setting can travel to the live site with the next copy. The check reads the site's own address, so a live site reached through its host's alias still counts as live. Live sites read exactly as before.

## 0.7.3 (2026-10-26)

- **Features shows which Scout Build is on the site.** "Scout Build 0.7.3" sits beside "Checked … ago", and when a newer Build is waiting it says so, with a link to the Plugins screen. Scout Build updates itself from the Plugins screen, so "Everything is up to date" never covered it. Joy asked for the version "somewhere up here".

## 0.7.2 (2026-10-26)

- **The theme says which features a client needs.** A `features` section in the client theme's `scout-setup.json` lists the features to use, in install order, and the ones this client doesn't need. Features shows "Recommended for this site, in install order" with numbered cards first, then "Also available" sorted by name, then "Not needed for this site" grayed out (still one click to turn on). Without the section, "Ready to turn on" now sorts by name. Joy asked for it so a new site's setup reads like a checklist.

## 0.7.1 (2026-10-26)

- **A theme can opt in to a feature its catalog row rules out.** Business info (Scout Core) shows as "Not used on this site" on the Scout Media and Recon themes, because they print their own SEO tags. Site Sync lives in Scout Core, so a theme that turns off Core's duplicate tags now declares `add_theme_support( 'scout-core' )` and the Turn on button comes back. Works for any feature slug in `not_with_themes`.

## 0.7.0 (2026-10-23)

- **Themes update themselves too.** A client theme that names its own repo (`Update URI` and `Scout Release` in `style.css`) now updates like the Scout plugins: a **Theme** box on the Features screen shows its version and "Up to date" or the version ready, with **Update the theme now**, and it auto-updates within a few hours of each release. Client repos stay private: an operator pastes a read-only key once, stored encrypted, sent to GitHub's API only. **Check for new versions** checks the theme as well. The release workflow for client repos is in `tools/theme-release/`. Joy asked for themes and plugins to update "so this is all cohesive and works together".

## 0.6.5 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.6.4 (2026-10-16)

- **Email color picks from the site's own colors.** Joy, on the Mac color grid: "not a good one". The field is now a hex box with a color chip, plus round swatches of the site's real colors: the block theme's palette (grays left out), the Cards and Reviews colors, and Scout's cerulean. Click one or type a code. On Maravela's the swatches include its rust, #A04E10.

## 0.6.3 (2026-10-16)

- **Email color.** The Email screen has a color picker for the test email's top bar and button. It starts on the color Scout found (Scout Cards, Scout Reviews, then a block theme's accent, primary or brand color) and keeps whatever you pick. Joy: Maravela's test came out in Scout blue, "the wrong theme". Themes can also set it with the `scout_brand_accent` filter.

## 0.6.2 (2026-10-16)

- **The Dashboard box shows the spam your forms blocked.** With Scout Forms Guard 0.2.0 or later on, a line under the reviews reads "23 spam submissions blocked this month · 140 in the last year", or "Spam protection is on. Nothing blocked yet this month." Joy wanted clients to see the protection working. Sites without Forms Guard show nothing.

## 0.6.1 (2026-10-16)

- **The Email test is a proper email.** Joy: "this email should be cuter though, no?" The plain-text test is now a small card in the site's own color (from Cards or Reviews, else Scout's cerulean) with the site icon, the headline "Your website can send email.", one plain line on what that means, and a Visit the site button. Inline styles only, so it looks the same in Gmail, Apple Mail and Outlook.
- **Fix: Copy for Claude printed "Andy&#039;s".** The site name in the report header is decoded.

## 0.6.0 (2026-10-16)

- **Email: the site's mail goes out through Google.** A new Scout, Website, Email screen. Enter a Gmail or Google Workspace address and a Google app password, Save, Send test. Every email the site sends (form notices, password resets, Scout checks) then goes through Google's mail server as that address instead of the host's, which on WP Engine landed in junk or vanished (Andy's form saved its entries but no email arrived). When a form asks to send as someone else, that address becomes the reply-to. The password is stored encrypted and never shown again, or can be set in wp-config.php as `SCOUT_MAIL_PASSWORD`. Replaces WP Mail SMTP on Scout sites.
- **A Scout box on the WordPress Dashboard,** first on the page: features on, AI search score, Google email on or off, your review rating and any new reviews, open requests, the site checks that need a look, and a box to send a change request without leaving the Dashboard.
- **Site health shows Site email:** host mail, through Google, or last test failed, with a link to the Email screen. Copy for Claude includes it.

## 0.5.0 (2026-10-15)

- **Copy for Claude.** Site health has a button that copies the whole page as plain text: every check with its state, value and note, the pages a search check names, each installed plugin with its version and whether it is on, the theme version, WordPress and PHP. Paste it into a chat instead of taking screenshots.
- **Site health fits on one screen.** Smaller cards in a tighter grid, and the section intros and progress bar are hidden on this page, so one screenshot shows everything.

## 0.4.9 (2026-10-15)

- **Direct answers checks the pages that matter.** The check now looks at the home page, then the pages its menus link to (read from the home page itself, so classic menus, Navigation blocks and hand-written headers all count), then top-level pages and their children, and skips utility pages (site map, search, the blog index, legal pages). Before, it took top-level pages alphabetically, so on Maravela's it checked the site map, gallery and booking forms and missed every service page. Visitor page notes count only for these same pages, and old counts are dropped.
- **Fill from theme again.** A button beside Check for new versions (only when the theme has a setup file) runs the setup file again. It still fills blanks only. For when a save from a page opened before a theme update blanked what the setup filled.

## 0.4.8 (2026-10-15)

- **The setup file fills people cards.** A `people` list in the `cards` section adds each person's card (name, title, intro, tags, photo, share picture) while the site has none. `pick_heading` is filled too.
- **Pictures the setup file placed can be swapped by a newer theme.** A card photo, logo or share picture the setup file imported itself counts as blank; one somebody picked by hand stays.

## 0.4.7 (2026-10-15)

- **The setup file sets the card's logo.** `logo` in the `cards` section (a theme path) fills the new Cards logo setting while blank.

## 0.4.6 (2026-10-15)

- **The setup file sets the card's share pictures.** `share_card` and `share_links` in the `cards` section (theme paths) become the wide pictures shown when the card or links page is shared, filled once while blank.

## 0.4.5 (2026-10-15)

- **The setup file fills the digital business card.** A `cards` section in the client theme's `scout-setup.json` sets the card's look, contact details, intro, booking spotlight, photo and link page rows once Cards is on. As with the other sections it fills only blanks and untouched defaults; the site admin's email also counts as blank, since on client sites it is often whoever set WordPress up.

## 0.4.4 (2026-10-15)

- **Fix: the setup file now wins over untouched defaults.** On North Crest the review page kept Scout's headline and blue button after the theme carried its own, because those settings had been saved earlier with the plugin's defaults and the setup file only filled blanks. A setting that still holds the plugin's own default now counts as blank. Anything someone actually changed stays as it is. The review page section runs again on the next admin page load.

## 0.4.3 (2026-10-14)

- **Fix: "Structured data: Missing" on Recon.** The Recon theme prints its own schema (`recon_head()`), and the check only knew Scout Media's. It now counts `recon_head()` and any theme that declares `add_theme_support( 'scout-seo-head' )`.

## 0.4.2 (2026-10-14)

- **Fix: "Direct answers" stuck on Checking.** The check loads the main pages from the site itself in the background, and many hosts and firewalls block a site from loading itself (scoutraleigh.com showed Checking for good). Now, when a visitor opens a page, Scout Build notes whether it carries FAQ answers, once a day per page, and the check uses those notes when the background scan can't run. Pages count for 30 days.

## 0.4.1 (2026-10-13)

- **The setup file fills Analytics too.** A client theme's `scout-setup.json` can carry `"analytics": { "gtm": "GTM-XXXXXXX" }`, and Scout Build puts the container ID into Scout Analytics once that feature is on. Blank only: an ID already set stays.

## 0.4.0 (2026-10-13)

- **The client setup file.** A client theme can carry `scout-setup.json` with the client's own facts (team, review page, chat, hours). When a feature is on, Scout Build fills it in on the next admin page load, so nobody retypes it. A section runs again only when the theme ships new facts, and it only fills blanks: anything already set in WordPress stays. Photos can be theme files; each becomes one Media Library item. Format in `includes/class-scout-hub-setup.php`.

## 0.3.10 (2026-10-13)

- **Scout in the toolbar.** The black bar at the top of every page shows a cerulean bolt and "Scout" ("Site Build" on unbranded sites). It opens Features, and its menu holds Site health and Request a change, which used to sit alone in the bar.

## 0.3.9 (2026-10-13)

- **Plans set.** Every site gets the basics (SEO, AI search, images, forms, analytics, call buttons, redirects, hours, accessibility, team credentials). Reviews come with The Partnership. Chat, business cards and case results are add-ons on any plan, and The Whole System includes every add-on. Feature cards show the right label for each. Turning features on or off is unchanged.

## 0.3.8 (2026-10-13)

- **Fix: sites showed "Up to date" when they were not.** Version checks used GitHub's API, which allows 60 calls an hour per server address; shared hosts like WP Engine run out, the check failed quietly, and the Features screen still said everything was up to date (North Crest stayed on Reviews 0.4.13 after 0.5.0 shipped). Sites now read one version list the Release workflow publishes as a plain download, with the API only as a fallback. If both fail, the Features screen says it could not reach GitHub instead of "Everything is up to date".

## 0.3.7 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.3.6 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.3.5 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.3.4 (2026-10-13)

- **"Up to date" stays current.** When the Features screen opens and the last version check is over an hour old, a fresh check runs in the background (WordPress cron), so a release from earlier today shows as "Update ready" on the next look. The "Check for new versions" button still checks right away.
- **Features keep themselves current.** Turning a feature on now also ticks its auto-update box. A one-time sweep ticks it for every Scout feature already on (and Scout Build itself), which fixes sites where Accessibility, Call buttons, Hours, Redirects and Team were installed with auto-updates off. Sites that block auto-updates are left alone.

## 0.3.3 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.3.2 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.
- **Your features, Site health and Request a change now use the screen name as the heading,** with the site name above it, so the title fits on a phone.

## 0.3.1 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 0.3.0 (2026-10-12)

- **Uses the shared frame.** Features, Site health and Request a change open in the same header, section nav and sign-off as every other Scout screen. The nav pills carry live hints (features on, AI search score, open requests).
- **One menu order everywhere:** Features, Site health, Business info, SEO, AI search, Image optimizer, then the rest, with Request a change last.
- **Site health shows the search listing checks** from Business info and SEO (page descriptions, visible to Google, social sharing), so there is one health screen.
- **Every card says whether it is current.** A green "Up to date" mark, or "Update ready: vX" with an **Update now** button that runs WordPress's own updater. Next to Check for new versions: "Everything is up to date" or how many updates are waiting, and when the site last checked.
- **Settings button on every feature that is on** and has a screen.
- **Fix:** the Business details fix link pointed at a page that no longer exists.
- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 0.2.6 (2026-10-12)

- **"Check for new versions" finds a release published minutes ago.** It
  now also clears each Scout plugin's own saved release check (kept for six
  hours) and WordPress's update list (kept for twelve), then checks again.
  Before, a new release could take hours to show as "Update ready".

## 0.2.5 (2026-10-12)

- **Turn on works for a feature released in the last few hours.** The hub
  remembers GitHub's release list for six hours, so a brand-new release
  (Scout Analytics) showed "No release is published yet". When a feature has
  no release in the remembered list, the hub now asks GitHub again before
  saying so.

## 0.2.4 (2026-10-12)

- **Fix a robots.txt file that hides WordPress's.** A real robots.txt file on
  the server (Yoast's file editor leaves one) overrides WordPress and
  everything Scout adds to it. Site health now links to a new screen that
  shows the file next to what WordPress would send, and when no rule in the
  file would be lost, switches to WordPress's version with one click. The file
  is renamed and kept as a backup, and can be put back from the same screen.
  A file with rules of its own is left for a person to review.

## 0.2.3 (2026-10-12)

- **The hub's tabs are easier to spot.** Features, Requests and Health are
  now large buttons with a one-line status under each ("4 features on",
  "2 open requests", "AI search 7 of 9"), and Requests and Health also sit in
  the sidebar menu, so nobody has to find them inside the page.
- **A new look for every settings screen.** The plugin's screens now share
  one Scout design: a clear page header with the section name, settings
  grouped into rounded boxes, pill buttons, and a short sign-off footer with
  how to reach us. On client sites the header reads "Site Build" instead of
  "Scout". Nothing about how the settings work changed.

## 0.2.2 (2026-10-12)

- **Features opens first.** Inside a theme's Build menu, Features moves to the
  top, so clicking the section lands on the hub instead of the theme's setup
  tools.
- **Cleaner Features screen.** A summary bar (features on, ready to add, AI
  search score, plan) with the plan and version check under it, then the
  cards grouped into "On this site" and "Ready to turn on". Features the theme
  already covers drop to a short "Not used on this site" list.
- **Catalog:** Cards is marked not used on the Scout Media theme, which
  already makes the cards and the links page.

## 0.2.1 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 0.2.0 (2026-10-12)

- **Ready for AI search.** Site health gains a second scorecard: AI crawlers
  allowed, the /llms.txt guide, structured data, business details, direct
  answers on pages, fresh content, and real authors with bios. Each check reads
  WordPress itself, counts what the theme or Scout Core already does, and links
  operators to the fix. A meter shows "5 of 7 ready".
- **Direct answers reads the real pages.** Client themes print FAQs their own
  way, so the hub loads up to 15 main pages in the background (WP-Cron) and
  counts the ones carrying FAQPage schema. Cached 12 hours; "Check for new
  versions" re-runs it.
- **Your features** shows the same score as a chip, linked to the scorecard.
- **Catalog:** adds AI search (`scout-aeo`), included in every plan.

## 0.1.0 (2026-10-12)

First release: the Site Build hub (phase 1 of `docs/site-build-hub-spec.md`).

- **Features.** A card for every Scout feature, read from `catalog.json` in the
  Scout Plugins repo (cached 12 hours, with a bundled copy as the fallback).
  Each card shows on, off or not installed, the version, an update waiting,
  and whether it is included in the site's plan or an add-on.
- **Operators only.** Turning features on or off, going back a version and
  setting the plan need an operator: Sean and Joy by default, or the emails in
  `SCOUT_HUB_OPERATORS` in `wp-config.php`. Everyone else sees the list and an
  "Ask us to add this" button.
- **Turn on** installs the latest GitHub release if needed (and any feature it
  requires) and activates it. **Turn off** deactivates and deletes nothing.
- **Go back** reinstalls the previous release and pauses automatic updates for
  that feature until a newer release than the one left behind comes out.
- **Request a change.** A form that emails team@scoutraleigh.com (or
  `SCOUT_HUB_REQUEST_EMAIL`) with the page, timing and an optional file, and a
  list on the site with each request's status. A "Request a change" link in the
  admin bar fills in the page being viewed.
- **Site health.** WordPress and PHP versions, updates waiting, HTTPS, search
  engine visibility, and WordPress's own Site Health result.
- Scout branding: the menu is **Scout** with the cerulean bolt, and the screen
  carries the Scout mark and a "Built and looked after by Scout Media" sign-off
  with the team's email and phone. `SCOUT_HUB_BRAND` set to false switches to a
  neutral Site Build with no Scout mention (partner-side and white-label sites).
- Sits inside the site's existing Build menu (Scout Build, Recon Build, or
  Scout Core's Scout menu), and only adds a top-level Site Build section when
  there is none.
