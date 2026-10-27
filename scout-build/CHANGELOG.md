# Scout Build changelog

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
