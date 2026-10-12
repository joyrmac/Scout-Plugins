# Site Build hub: spec (draft, 2026-10-12)

One screen in every client dashboard that shows which Scout features the site runs, lets Sean and
Joy turn them on and off, and gives the client a few everyday tools. Each feature stays its own
plugin with its own release, so a bad update to one never breaks the others.

Status: draft for Sean and Joy. Nothing built yet.

## Decisions already made (Joy, 2026-10-12)

- Separate plugins underneath, one control screen on top (option B).
- Only Sean and Joy can turn features on or off. Clients see what is on and can ask for more.
- Include fleet check-in, change requests, tier labels and rollback.
- Site Sync (the ratified content-sync spec in `scout-core/docs/site-sync-spec.md`) moves into the
  hub as a later phase, so every site has one dashboard.

## Naming and placement

- Plugin: `scout-build` (code name). Self-updating like the others.
- Menu label: **Site Build** on client sites, **Scout Build** on scoutraleigh.com and Recon. Top-level,
  directly under Dashboard (build-standards "Dashboard standard").
- The hub owns the `site-build` menu. Scout Cards already registers a top-level `site-build` menu,
  so Cards changes to nest under the hub when the hub is active (and keeps its own menu when not).
- Partner-side sites: do not install the hub there until the labels are cleared with that agency.
  Nothing in it may say "Scout" on those sites.

## Phase 1: the hub (about 3 to 4 days)

1. **Features screen.** A card per Scout plugin: what it does in one plain sentence, status
   (Not installed, Off, On, Update available), version, and last updated.
   - The list comes from a `catalog.json` in the Scout-Plugins repo (slug, name, one-liner, tier,
     law-firm flag, requires), fetched from GitHub and cached for 12 hours.
   - Operators press **Turn on** to install from the latest GitHub release and activate, or
     **Turn off** to deactivate. Deleting stays a manual WordPress action, so data is never lost by
     accident.
2. **Operator lock.** Only listed users can change features. Default list: Sean's and Joy's Scout
   accounts. Set per site with a `SCOUT_BUILD_OPERATORS` constant (emails) in `wp-config.php`, so
   a client admin cannot add themselves from the dashboard.
3. **Tier labels.** Operators set the site's plan (The Build, The Partnership, The Whole System).
   Each feature shows "Included in your plan" or "Add-on". Clients see an **Ask us to add this**
   button on anything that is off.
4. **Rollback.** Each feature card has **Go back to the previous version**. It installs the prior
   GitHub release of that plugin, pauses auto-updates for it until the next release, and logs who
   did it. No extra storage needed: GitHub keeps every release.
5. **Request a change.** A short form: what you need, which page (prefilled with the page you came
   from), how soon, optional file. It emails team@scoutraleigh.com and keeps a list on the site so
   the client sees each request and its status (Received, In progress, Done). ClickUp hand-off is
   a later add-on.
6. **Site health strip.** WordPress and PHP versions, WordPress's own Site Health result, updates
   waiting, SSL on, and whether search engines are allowed. All read from WordPress itself.

## Phase 2: keeping an eye on every site (about 3 days)

7. **Fleet check-in.** Once a day each site sends its versions (WordPress, PHP, theme, every
   plugin) and health flags to a small Scout endpoint (a Cloudflare Worker, like Bliss Compass).
   Signed with a per-site key. No visitor or client data is sent. The fleet list in `fleet.md`
   fills itself instead of being typed by hand.
8. **Activity log.** Who logged in, who added an admin user, which plugins or themes changed, and
   which pages were published or deleted. Kept 90 days. Emails Sean and Joy when a new admin
   account appears.
9. **Monthly site health email.** Built from the health strip and the activity log, sent with the
   client update email (design 5.23).

## Phase 3: the law firm pack (about 5 to 7 days)

Built for our law-firm clients first; most of it helps every client.

10. **Disclaimer manager.** One place for the firm's required lines: an "Attorney Advertising" legend,
    a "Prior results do not guarantee a similar outcome" line, and a testimonial disclaimer.
    Placed automatically under case results, reviews and testimonials, and in the footer. Starting
    presets for North Carolina, Virginia, Florida and DC. The firm's named approver confirms the
    wording; presets are a starting point, never legal advice.
11. **Approval gate.** For chosen content types (practice pages, case results, attorney bios), a page
    cannot be published until someone records "Approved by" and the date. Keeps a log, because bar
    rules ask firms to keep advertising records.
12. **Content freshness.** Each page shows "Last reviewed". A list flags pages not reviewed in six
    months, so legal pages stay current (playbook: review every three to six months).
13. **Form entry retention.** Gravity Forms entries are deleted automatically after a set number of
    days (default 90). Intake details then do not sit in WordPress forever. A short note on intake
    forms reminds visitors not to send confidential details through the website.
14. **Redirect manager.** Add and test 301 redirects from the dashboard, with a 404 log that suggests
    redirects. Nothing in the platform does this today.
15. **Accessibility helpers.** A report of images missing alt text, a page builder for the
    accessibility statement, and a headings check (no skipped levels).
16. **Broken link check.** A weekly scan of internal links and a list of anything broken.

## Phase 4: Site Sync

17. Move the ratified Site Sync spec (theme manifest, freshness table, auto-sync) into the hub as
    the content tab. Removes the four per-theme updaters.

## Considered and left out (for now)

- **Two-factor login and firewall rules.** WP Engine and dedicated security plugins do this better;
  we recommend one instead of building our own.
- **Backups.** WP Engine already backs up daily. The hub links to it.
- **Cookie consent banner.** Worth doing for consent mode, but it is its own plugin; listed in the
  catalog later.
- **Billing or pricing inside the dashboard.** Out of scope; no money in client dashboards.

## What we need before building

| Need | From | Why |
|---|---|---|
| Confirm Scout-Plugins releases stay public | Sean | Sites download plugins from GitHub releases without a login |
| Operator emails per site | Sean and Joy | The lock that keeps feature switches ours |
| Plugin installs allowed on WP Engine (no `DISALLOW_FILE_MODS`) | Sean | "Turn on" installs plugins |
| A Cloudflare Worker and a signing key for fleet check-in | Sean (Phase 2) | Where sites report in |
| Which states' disclaimer presets first | Joy and Sean (Phase 3) | NC, VA, FL and DC research already exists |
| Sign-off on the default entry retention (90 days) | Sean (Phase 3) | Deleting form entries is permanent |

## Build order and rules

- Phase 1 first, released and tested on scoutraleigh.com, then North Crest (first on the update
  channel), then the rest.
- Every phase is a normal Scout-Plugins release: version bump, changelog, `tools/core-contract.sh`
  updated if it calls Scout Core.
- Plain-language labels everywhere, sentence case, no em dashes, "Site Build" on client sites.
