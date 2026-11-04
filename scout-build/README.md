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

Seven items, the same words in the sidebar and on the screen, built around
who is looking:

| Item | Who | What it holds |
|---|---|---|
| **Overview** | everyone, the first screen | Is the site all right (from Site health), Needs you (the Inbox counts), With Scout (updates, the theme, the last Site log lines), the things a client does here (change a page, write a post, update hours, ask for a review), and "Want something added? Ask Scout." |
| **Your business** | client, editor | Business info, Call buttons, Hours, Team, Case results, Reviews and Review settings, Cards & Links, Booking, Events, Members, Partners, Newsletter, a companion plugin's own types. Each fact typed once. |
| **Content** | editor | Site Sync, Redirects, Image optimizer, Writing. |
| **Search and AI** | client admin | SEO, AI search, Analytics, Accessibility, Spam protection, Site checks, robots.txt. |
| **Site health** | everyone | The whole report, Copy for Claude. |
| **Inbox** | client, operator | Everything waiting on a person with one count: change requests, chat questions, new reviews, Ask for reviews. Other plugins add a queue with the `scout_hub_inbox` filter. |
| **Build** | operators only | Features (turn on, off, back), Updates (theme and key, plugins, Fill from theme, Site Sync, Import posts, Site settings, Change log), Site log, Email, Chat settings, Customer list, a theme's own screen. Hidden from everyone who is not an operator; every action on it still checks for one. |

The shared admin kit (`tools/admin-kit/`) files every Scout screen into these
seven by its slug, and `tools/tests/admin-kit-groups.php` fails when a screen
drifts from the map. A screen never moves its address; only where it is filed.

## Settings in `wp-config.php`

| Constant | Default | What it does |
|---|---|---|
| `SCOUT_HUB_OPERATORS` | `sean@scoutraleigh.com, joy@scoutraleigh.com` | Who can turn features on and off, go back a version, set the plan, and change request statuses. Must also be a WordPress admin. |
| `SCOUT_HUB_REQUEST_EMAIL` | `team@scoutraleigh.com` | Where change requests and the "hidden from Google" alert are emailed (the alert can also be changed with the `scout_build_alert_email` filter). **Set this on partner-side sites** so nothing routes to a Scout address. |
| `SCOUT_HUB_BRAND` | `true` | Labels the menu **Scout** with the bolt and shows the "Built and looked after by Scout Media" sign-off. **Set to `false` on partner-side or white-label sites**: the menu then says Site Build and nothing mentions Scout. |
| `SCOUT_HUB_READ_TOKEN` | (none) | Optional. A key of 20+ characters that lets the Scout Client Hub read `scout-build/v1/log`, `/requests` and `/site` (Bearer). Normally operators make the keys on Scout → Site log instead; a constant here wins over those. |
| `SCOUT_HUB_WRITE_TOKEN` | (none) | Same, plus `POST /log` for note lines (how Claude logs work as it ships). Cannot change anything else. |
| `SCOUT_HUB_LOG_API` | `false` | On a white-label site (`SCOUT_HUB_BRAND` false) the endpoint stays off unless this is true. |
| `SCOUT_THEME_TOKEN` | (none) | The read-only GitHub key for the theme's own repo, instead of saving it on Scout → Updates. See "Theme updates" below. |
| `DISALLOW_FILE_MODS` | (WordPress) | When true, features that are not installed must be uploaded by hand; on and off still work. |

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

## The Site log

**Scout, Site log** keeps one line for every change to the site, written by
the plugin that made it: Scout plugin and theme updates (with the changelog
headline), features turned on, off or sent back, Site Sync runs, the setup
file, change requests coming in and moving, and the hidden-from-Google alert.
Operators add notes for work done by hand. Each line has a kind, a topic, a
status (done, planned, waiting on you), a version, a measure and a "client
sees" flag with its own plain wording, which is what the client's monthly
update is built from. A done line never changes; corrections are a new line.

**Your site (0.11.0):** everyone who is not an operator sees the log as Scout → Your site: client
lines only, in client wording, with Waiting on you (Approve button), This month, Your requests and
Coming up. To put something up for the client's OK, add a note with status "Waiting on you" and
Client sees on.

**Hub keys:** operators press Make keys on the Site log screen; the read key goes in the Scout
Client Hub Sheet, the write key is Claude's. Shown once, stored encrypted.

One-time import: upload the site's file from `docs/site-log-import/` on the
Site log screen. Spec and the hub it feeds: `../docs/site-log-spec.md`.

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
