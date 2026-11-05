# Scout Build

The Site Build hub: one dashboard screen showing which Scout features a site
runs, with the controls to turn them on, off, or back a version, a way for the
client to request a change, and a quick health check. Each feature stays its
own plugin with its own release. Spec: `../docs/site-build-hub-spec.md`.

Standalone: does not need Scout Core.

## Where it shows up

One menu, **Scout**, on every site (the Scout Site Standard §7, v1.2:
`Scout-Internal/ops/playbook/site-standard.md`). Inside the site's existing
Build menu when there is one (Scout Core's **Scout** menu, or a theme's own
menu, which the hub relabels Scout until that theme's conversion), otherwise
as its own top-level section with the cerulean bolt. **Site Build** instead
of Scout when `SCOUT_HUB_BRAND` is false.

The same words in the sidebar and on the screen, built around who is looking
(dashboard redesign, Joy 2026-11-04). The full map, and how to file a new
screen or feature, is in `../docs/dashboard-map.md`.

| Item | Who | What it holds |
|---|---|---|
| **Home** | everyone, the first screen | Is the site all right, what needs you, what Scout is doing, the things a client does here, and one line to ask for more. Clients also find **Site history** here (what Scout has done on the site, with Approve). |
| **To do** (key `inbox`) | everyone | One list of everything waiting on a person, newest first, with filter chips: chat questions, new reviews, change requests and their status, site check requests, booking requests. Plugins add items with `scout_hub_inbox_items` (or a count row with `scout_hub_inbox`). Each person picks Inbox emails: never, once a day, or every new item. |
| **Reports** | owner, operator | Recon's numbers, when Recon is on. Empty groups never show to clients. |
| **Business** | everyone | Facts typed once: Business info, Hours, Team, Case results, a companion plugin's own types. |
| **Site features** | owner, operator | What visitors see and use: Call buttons, Chat, Reviews and Review settings, Cards & links, Newsletter, Redirects, Free site check. |
| **Search** | owner, operator | SEO, AI search, Analytics and the cookie notice, Search engine rules (robots.txt), and later site search and the sitemap. |
| **Legal pages** | owner, operator | Accessibility statement now; privacy policy, terms and law-firm disclaimers as they arrive. |
| **Site status** | owner, operator | The whole report (the Site status screen), Search health, Spam protection, the image optimizer. |
| **Scout tools** | operators only | Features, Updates, Site history, Email, Chat, Customer list, Site Sync (Page copy on Updates), the Change log, a theme's own screen. Reads **Build tools** when `SCOUT_HUB_BRAND` is false. Every action on it still checks for an operator. |

Plus modules (Bookings, Events, Members, Partners, Writing room) keep their own
sidebar sections and only appear on sites that have them.

The shared admin kit (`tools/admin-kit/`) files every Scout screen into these
groups by its slug, and `tools/tests/admin-kit-groups.php` fails when a screen
drifts from the map. A screen never moves its address; only where it is filed.

## Settings in `wp-config.php`

| Constant | Default | What it does |
|---|---|---|
| `SCOUT_HUB_OPERATORS` | `sean@scoutraleigh.com, joy@scoutraleigh.com` | Who can turn features on and off, go back a version, set the plan, and change request statuses. Must also be a WordPress admin. |
| `SCOUT_HUB_REQUEST_EMAIL` | `team@scoutraleigh.com` | Where change requests and the "hidden from Google" alert are emailed (the alert can also be changed with the `scout_build_alert_email` filter). **Set this on partner-side sites** so nothing routes to a Scout address. |
| `SCOUT_HUB_BRAND` | `true` | Labels the menu **Scout** with the bolt and shows the "Built and looked after by Scout Media" sign-off. **Set to `false` on partner-side or white-label sites**: the menu then says Site Build and nothing mentions Scout. |
| `SCOUT_HUB_READ_TOKEN` | (none) | Optional. A key of 20+ characters that lets the Scout Client Hub read `scout-build/v1/log`, `/requests` and `/site` (Bearer). Normally operators make the keys on Scout → Site history instead; a constant here wins over those. |
| `SCOUT_HUB_WRITE_TOKEN` | (none) | Same, plus `POST /log` for note lines (how Scout's tools log work as it ships). Cannot change anything else. |
| `SCOUT_HUB_LOG_API` | `false` | On a white-label site (`SCOUT_HUB_BRAND` false) the endpoint stays off unless this is true. |
| `SCOUT_THEME_TOKEN` | (none) | The read-only GitHub key for the theme's own repo, instead of saving it on Scout → Updates. See "Theme updates" below. |
| `DISALLOW_FILE_MODS` | (WordPress) | When true, features that are not installed must be uploaded by hand; on and off still work. |

## Release rings (update order) and the pause switch (0.11.1+)

Site Standard rule 19: a Scout plugin release installs itself on
scoutraleigh.com and staging first (ring 1), Recon a day later (ring 2),
client sites two days after it is out (ring 3). Screens call this the
**update order** (first, a day later, two days later). Until then the automatic
update waits and Build, Updates says when it installs; Update now always
works. Set the ring with `SCOUT_HUB_RING` (1, 2 or 3) in wp-config.php or on
the Updates screen; otherwise the address decides. Two brakes:

- **One site:** Build, Updates, Pause automatic updates for 7 days.
- **Every site:** add `{"slug": "...", "version": "..." or "*", "note": "..."}`
  to `hold` in `catalog.json` (both copies). Sites read it within 12 hours,
  or at once with Check for new versions. Remove the line to release it.

## Theme updates

A client theme can update itself like the Scout plugins, from the client's
own private repo. The theme adds `Update URI: https://github.com/OWNER/REPO`
and `Scout Release: <folder name>` to its `style.css` header, and its repo
publishes each version with the workflow in `tools/theme-release/`. On the
site, an operator pastes a read-only key (a fine-grained GitHub token with
Contents read access to that one repo) into the **Theme** box on Scout →
Updates. The key is stored encrypted and sent to api.github.com only; the zip
itself is fetched from GitHub's signed address without it.

The Theme box then shows the installed version, "Up to date" or the version
ready, **Update the theme now**, and the theme auto-updates within a few hours
of each release. **Check for new versions** checks the theme too. A theme
without `Update URI` keeps updating by upload. Steps for each client:
`tools/theme-release/README.md`.

## The Site log (Site history on screen)

**Scout, Site history** keeps one line for every change to the site, written by
the plugin that made it: Scout plugin and theme updates (with the changelog
headline), features turned on, off or sent back, Site Sync runs, the setup
file, change requests coming in and moving, and the hidden-from-Google alert.
Operators add notes for work done by hand. Each line has a kind, a topic, a
status (done, planned, waiting on you), a version, a measure and a "client
sees" flag with its own plain wording, which is what the client's monthly
update is built from. A done line never changes; corrections are a new line.

**The client's view (0.11.0):** everyone who is not an operator sees the log as Scout → Site history: client
lines only, in client wording, with Waiting on you (Approve button), This month, Your requests and
Coming up. To put something up for the client's OK, add a note with status "Waiting on you" and
Client sees on.

**Hub keys:** operators press Make keys on the Site history screen; the read key goes in the Scout
Client Hub Sheet, the write key is for Scout's tools. Shown once, stored encrypted.

One-time import: upload the site's file from `docs/site-log-import/` on the
Site history screen. Spec and the hub it feeds: `../docs/site-log-spec.md`.

## The feature list

`catalog.json` at the root of this repo, mirrored in `scout-build/catalog.json`
(CI fails if the two differ). Each entry: `slug`, `name`, `summary` (one plain
sentence), `tier` (`build`, `partnership`, `whole` or `addon`), `law`,
`requires` (other slugs), and optional `client_specific` (shown only where
installed). Adding a feature to the list needs no hub release: sites pick it up
within 12 hours, or at once with **Check for new versions**.

## Installing from releases

Turn on and Go back download the `<slug>.zip` asset from this repo's GitHub
releases (`<slug>-v<version>` tags), the same releases the updater uses. The
repo's releases must stay public for this to work without a login.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Change requests visitors send through Scout Chat: the request in their words, their name, email and phone, and the page (only when Scout Chat is on and a visitor asks for a change there) | This website's database, as private change request posts (the Requests screen) | Until someone deletes it. Deleting the plugin keeps the request list. The site's privacy tools export a request by the email the visitor gave; the eraser tool keeps the request and takes out their name, email and phone. | Scout's Client Hub reads the request and the visitor's name through the site's Hub key, with email addresses and phone numbers blanked first. Only when a Hub key is set. |
| The visitor's name and the first words of a chat change request, as a line in the Site log (only when Scout Chat is on and a visitor asks for a change there) | This website's database, as a private Site log line | Until someone deletes it. Deleting the plugin keeps the Site log. The site's privacy eraser tool replaces the name with Scout Chat. | Scout's Client Hub, which reads the Site log through the site's Hub key, only when a Hub key is set |
| Names or emails and short previews of waiting visitor messages (chat questions, booking requests, new reviews and similar) (only for staff who chose daily or every-item Inbox emails) | Read from the plugins that hold them and listed in Inbox emails. This plugin keeps no copy. | Not kept by this plugin | Emailed to site staff who turned on Inbox emails |
| Every email the website sends, including form notices, booking and chat emails, and replies to visitors, which can carry what visitors typed (only when the Email screen has a Google address and app password) | Sent through the Google mailbox set on the Email screen. This plugin keeps no copy. | Not kept by this plugin | Google (Gmail), which sends the site's email |

This plugin sets no cookies and collects nothing from visitors itself. The items above come from other Scout plugins, mainly Scout Chat, and from how the site's email is sent.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
