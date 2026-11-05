# Scout Team changelog

## 0.4.0 (2026-11-05)

- **Business card box on the People profile (Joy, 2026-11-05).** The Card only phone and email grew into a Business card box under Profile: Intro (one or two sentences in their own voice), Why talk to them (one line for the team card), Tags (comma separated), Card phone and Card email. Stored in the profile like every other field (`card_intro`, `card_why`, `card_tags`), through the same clean and store path. Scout Cards reads them through `Scout_Team::card()` (`intro`, `why`, `tags`), so they are typed once, in People.
- **Card words stay card only.** Like the card phone and email, they never show on the profile page or the team page, never go into the schema and are never sent by the REST API. `Scout_Team::card_keys()` lists all five.
- **Send them a link takes the card words too,** for people with A business card on: their intro, a "why talk to you" line and tags. They wait as pending changes with the rest and show in the review table. The page still never asks for email, phone, card phone or card email.
- **Moved:** the "Card only" group (Card phone, Card email and "Business card only. Never on their page, the team page or in search.") left the Shows on box for the Business card box.

- **Written by.** Posts get a Written by box (a panel in the block editor's sidebar, a box in the classic editor). Pick one or more people from People as the byline, with or without a login: guest writers, speakers with no login, and posts written by staff for someone else. Stored on the post as `_scout_people_authors`, in order. People whose byline is off can be picked, with a note that the post leaves them out until it is on. Posts only; the `scout_people_byline_post_types` filter adds other types.
- **Bylines use it.** With people picked, the author name is theirs ("Ana Ruiz, Ben Cole & Dee Fox"), the author link goes to the first person's profile page (no address when that page is off), and `the_author_posts_link()` links each name. The article schema's author is each person's existing Person node. With nobody picked, nothing changes for any post.
- **`Scout_Team::byline_people( $post_id )`.** Returns who a post's byline credits: the people picked, or the profile linked to its login. For Scout Build's Real authors check later. `Scout_Team_Byline::posts_for()` gives a theme the newest posts that credit a person.
- **The People screen shows "Byline on N posts"**: posts where the person is picked plus their login's posts, each counted once.
- **People rows are easier to read.** The photo sits on the left and everything else in one column beside it: the name with Edit and Send them a link on the right, then title and groups, then tags, then what needs a look on one short line. Section labels like "Runs the site" are small labels again. On a narrow screen the buttons drop under the name. Same links and actions.
- **The REST API hides what it never should have shown.** For anyone who cannot edit posts, `/wp-json/wp/v2/scout_team` and the REST search leave out people whose own page is off and drafts, and asking for one by ID answers "not found", the same as the page. Card only contact, details waiting for an OK and link data are never sent to them. Editors and the block editor see everyone, as before.
- The Shows on help line for the byline now mentions Written by.

- **A business card works.** With Scout Cards on, a person who is live with A business card switched on gets a card, which reads their name, title, photo, card phone, card email, and their profile links and short bio when the card shows them. Two new functions give Cards what it may show: `Scout_Team::card()` and `Scout_Team::card_ids()`.
- **Profile pages that are off stay out of the site's own search results**, the same way they stay out of the sitemap. A person who shows on a card only never turns up there.
- **Add a person from the Cards screen starts with A business card on** (`&card=1`). Opened from People, the defaults are the same as before.

## 0.3.0 (2026-11-05)

- **Add a person is a short walkthrough.** The People screen's Add a person button now opens three steps on one screen inside the People section, with a numbered progress row: who they are (name, title, groups with Team ticked), photo and short bio (a photo from the media library, its alt text, which uses their name when empty), and where they show (the same Shows on switches and defaults, plus Writes posts as, which turns the byline on). Save as draft, Publish, or Save and send them a link. It saves through the edit screen's own code, so the profile, groups and defaults are the same, then opens the full edit screen with "Add credentials and links here when you have them." It works without JavaScript too: all three steps show and the photo is a plain upload. "Use the full editor" still opens WordPress's own Add screen.
- **Send them a link.** On each row of the People screen, on the edit screen and at the end of Add a person. It makes a one-time link the owner copies and sends; nothing is emailed. The person fills in their own name, title, short bio, photo, credentials with sources and profile links, with no login. The page never asks for email, phone or the card only contact. The link ends after 14 days, works once, allows a few tries an hour, and only a keyed hash of it is stored. The page has a nonce and a honeypot field, takes JPG, PNG or WebP photos up to 5 MB, cleans every field, is never listed by search engines or cached, and keeps the analytics tag off. It uses the theme's header and footer.
- **What they send waits for your OK.** It is kept apart from the live profile, so nothing visitors see changes until it is approved. The person shows under Needs a look and in To do as "Sent their details, waiting for your OK". The edit screen shows each field now and as sent, with Approve (copies it into the profile) and Discard (deletes it and the photo sent with it).
- **Privacy tools.** WordPress's export and erase tools cover details waiting for an OK, found by the email on the person's profile or their login. The visitor data list says what the link page keeps.
- **Nothing changes for visitors.** Existing pages, the team page, the grid and the schema read only the live profile. The only new public page is the link page, reachable only with a working link. Deleting the plugin now also removes every link and any details still waiting.

## 0.2.0 (2026-11-05)

- **Plainer words on the People screens.** Help text cut to one short sentence each, in the dashboard word rules (admin kit DESIGN.md 2a). "Full list (bulk edit and trash)" now reads "Edit many at once". Labels only; nothing visitors see changes.

- **Scout Team is now Scout People.** One profile for each person the public sees: your team, speakers, writers and members who want to be listed. It has its own People section in the Scout menu (shared admin kit 1.11.0) with three screens: People, Groups and People settings. The plugin folder and slug (`scout-team`), the `scout_team` post type, the stored profile and every `/team/` address are unchanged, so no data moves and no link breaks. People settings keeps its old address (`?page=scout-team`).
- **Groups.** A new private `scout_people_group` taxonomy. The update makes Team, Speakers, Writers and Members, plus Attorneys when any profile is an attorney, and puts every existing person in Team (attorneys in Attorneys too). A new person starts in Team. Groups are never deleted.
- **Shows on.** Six switches per person: their own profile page, the team page, a business card, a byline on their posts, the speakers page and chat answers. Existing profiles keep what they had: profile page and team page on, byline on when a login is linked. With the profile page off, visitors get "page not found", the page leaves the sitemap and the `/team/` archive, the grid lists the person without a link, the schema drops the address, and bylines keep WordPress's own author link. Editors can still open it, marked noindex. The team page shows live people in the Team group with the team page switch on.
- **Card only phone and email.** For a person's business card only. They never show on the profile page or the team page and never go into the schema.
- **Sources on credentials.** Every bar admission, school, award and membership line has a Source. The page and the schema show only lines with one. Lines saved before this release were marked "Added before sources were tracked", so live pages stay the same. A new line with no source is saved but hidden. Memberships moved from one-per-line text to lines with a source, keeping every old value.
- **Needs a look.** A person needs a look when there is no photo, the photo has no alt text, a credential line has no source, or they are not live yet.
- **The People screen.** One status line (people, on the team page, cards on, need a look), filter chips (Everyone, each group, Needs a look), and one row per person with photo or initials, title, groups, where they show, what needs a look and one Edit button. The Everyone view puts the people who run the site first, then Team, Speakers, Writers, Members (listed) and everyone else. The team list moved here from the settings screen, which now holds only the settings and the shortcode help.
- **Photo alt text.** A person's photo with no alt text in the media library reads as their name wherever a theme prints it.
- **Address changes and the Site log.** When a live person's address changes, Scout Redirects (when it is on) adds a 301 from the old `/team/` address. Going live, changes and coming off the site each add one line to the Site log when Scout Build is on.

## 0.1.14 (2026-11-05)

- **The Team screen opens with one status line.** The three status cards are now one line: how many people, how many profiles are complete, and whether law firm details are on. The team list comes next. The settings (now titled Profiles, bylines and the grid) and the shortcode list move into a closed More options box. Same fields and option.
- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.1.13 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).

## 0.1.12 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.1.11 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.1.10 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.1.9 (2026-10-30)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.1.8 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1. The settings screen's tab now reads "Team settings", so it is never confused with the list beside it.

## 0.1.7 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.1.6 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 0.1.5 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.4 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.3 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.2 (2026-10-13)

- **One Scout section in the sidebar.** The team members list moved from its own sidebar item into the site's Build menu (Business tab), and the Build menu stays open while editing an entry. Sites without a Build menu keep the old sidebar item.

## 0.1.1 (2026-10-13)

- Ships the shared admin kit 1.3.0: the Scout menu bar is grouped into tabs (Home, Search, Website, Business, Reviews, Chat), and a long Scout sidebar shows one link per group.

## 0.1.0 (2026-10-13)

- First release. A `scout_team` post type with a profile page for each
  person at /team/name/ and a team archive at /team/.
- Profile fields: role, photo, short bio, education, bar admissions (law
  firm sites), awards and honors, professional memberships, military
  service, languages, optional email and phone, profile links, and the
  WordPress login they write posts as.
- Edit screen with a live preview card and a trust checklist (real photo,
  bio and role, byline, education, bar admissions, profile links).
- Person schema with hasCredential, alumniOf, memberOf, award,
  knowsLanguage and sameAs, wrapped in a ProfilePage. Joins the Scout Core
  graph with worksFor pointing at the business when Scout Core is active;
  prints its own graph otherwise.
- `[scout_team]` grid shortcode, author links that point to the writer's
  profile, and click events through Scout Analytics when it is installed.
- Settings under Scout Build / Site Build → Team.
