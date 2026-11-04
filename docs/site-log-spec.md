# Site log: spec (2026-11-04)

One line on the client's own site for every change made to it, written by the plugins that make the
change, with room for a plain-words note from Joy, Sean or Claude. It is the record the Scout Client
Hub (the Google Sheet), the client's "Your site" screen and the monthly client message all read from.
Nothing is typed twice.

Plan and the four decisions: the Scout Client Hub page (https://claude.ai/artifact/1MnV5zAn1e95noTvzhiNhc)
and `Scout-Internal/ops/client-hub.md`.

Status: approved (Joy, 2026-11-04: yes to all four calls). Built as Scout Build 0.9.0 and Scout Chat 0.3.0
on 2026-11-04, awaiting Joy's OK to release. This is step 1
of the four-step build; steps 2 to 4 (the Google pull, the "Your site" screen, the monthly message)
get their own specs once this ships.

## Decisions this rests on (Joy, 2026-11-04)

1. The client's WordPress site is the record of its own changes. Google is the view.
2. Clients see a "Your site" screen in their dashboard and get a monthly message.
3. Clients who text (Patrick) get a drafted text that Joy sends by hand. No Google Voice yet.
4. Contacts, ownership and the logins list move to the Sheet's Clients tab, shared with joy@ and
   sean@ only. Secrets stay in the password manager. Nothing of that lives on a site or in a repo.

What this supersedes in `site-build-hub-spec.md`: Phase 2 items 7 (fleet check-in to a Worker),
8 (activity log) and 9 (monthly health email). The Google Sheet pulls instead of a Worker pushing,
the activity log is this log, and the monthly email is step 4 of the hub plan.

## Where it lives

- Plugin: **Scout Build** (`scout-build`), release **0.9.0** (minor: new feature; main took 0.8.0 for the Updates screen the same day). Standalone, as
  Scout Build is today. Lines from Scout Core (Site Sync) arrive through an action Scout Core already
  fires, so Scout Build never requires Scout Core.
- Post type `scout_hub_log`, private, no public UI (`show_ui` false), like `scout_hub_request`.
- Screen: **Scout → Site log**, after Request a change in the section nav. Operators (the
  `SCOUT_HUB_OPERATORS` list) see everything and can edit; other administrators read.
- Partner-side sites (`SCOUT_HUB_BRAND` false): the log runs and the screen says Site log, with no
  Scout wording. The read endpoint stays off unless `SCOUT_HUB_LOG_API` is true. The hub's puller
  works from a fixed list of Scout sites, never a wildcard, so a partner site cannot land in the
  Scout Sheet by accident.

## The entry

| Field | Stored as | Rule |
|---|---|---|
| Date | `post_date` | When it happened on the site, not when it was logged. Imports keep their own date. |
| Title | `post_title` | Plain words, under 90 characters, what changed. "Theme 1.1.8: hero loads faster on phones, link preview card added". |
| Detail | `post_content` | Optional. Internal detail: what was behind it, the menu path, the PR. Never shown to the client. |
| Client wording | `_scout_log_client_text` | Optional. The sentence the client reads. Falls back to the title. **If it claims a result, it carries the number** ("largest paint 4.1s to 3.3s on a phone"), taken from the Measure field at the time of the work, never reconstructed later. |
| Show the client | `_scout_log_show` (0/1) | Default by kind (table below). Operators can flip it per line. |
| Kind | `_scout_log_kind` | What wrote it: `theme`, `plugin`, `feature`, `content`, `setup`, `request`, `approval`, `alert`, `note`. Fixed list. |
| Topic | `_scout_log_topic` | What it is about, the old site-updates Type column: `speed`, `brand`, `content`, `email`, `setup`, `plugin`, `launch`, `seo`, `forms`, `other`. One per line. |
| Status | `_scout_log_status` | `done` (default), `planned` (shows under Coming up), `waiting` (on the client). Planned and waiting lines are the only ones that can change later; done lines are append-only. |
| Version | `_scout_log_version` | The theme or plugin version the change shipped in, when there is one. "1.1.8", "Scout Build 0.6.3". |
| Measure | `_scout_log_measure` | Optional before and after, in the client's units: "PageSpeed phone 85 to 88". Filled when the work is done. |
| Source | `_scout_log_source` | Who or what wrote it: the plugin name ("Scout Build", "Scout Core"), or a person ("Joy", "Sean", "Claude"). People lines also set `post_author` when a WordPress user made them. |
| Reference | `_scout_log_ref` | Optional link or ID: the request post ID, a GitHub release, a PR. |
| Import ID | `_scout_log_import` | Set only by the history import. Stops a second import from making duplicates. |

Rules:

- **A done line never changes.** Corrections are a new line that says what was wrong. The log is the
  record; editing it in place is how the North Crest regression hid for three months.
- **No money, no visitor data, no secrets.** Lines describe the site. Spam counts and review totals
  are fine; names from form entries, prices and keys are not. The read endpoint never returns
  post_content for `alert` lines, which can mention addresses.
- **Plain language** on anything the client can see, Scout writing rules, sentence case, no em dashes.

## Who writes it, and when

Every row below is automatic. The plugin that does the work logs it in the same request, after the
work succeeds, so a failed update never logs as done.

| Event | Hook | Kind | Line | Client sees |
|---|---|---|---|---|
| Scout plugin updated | `upgrader_process_complete` (type plugin), for plugins whose Update URI points at Scout-Plugins | `plugin` | "Scout Reviews 0.5.5: " + the first bold line of that version's entry in the plugin's `CHANGELOG.md` (shipped in every zip). No changelog: version only. | Yes |
| Feature turned on, off, or sent back a version | `Scout_Hub_Features::log()` (the current 30-row option becomes this; `recent_log()` reads the post type) | `feature` | "Scout Hours turned on by Joy" / "Scout Chat sent back to 0.1.0 by Sean" | On: yes. Off and go back: no |
| Theme updated | `upgrader_process_complete` (type theme) | `theme` | "Theme 1.1.8: " + the top entry's title from the theme's `CHANGELOG.md` when the theme carries one, else the version. Version from `style.css` after the update. | Yes |
| Site Sync applied copy | `scout_sync_applied` (Scout Core 1.4.0+, already fires with the journal rows and who ran it) | `content` | "Page copy updated on N pages from the theme" (N from the journal) | Yes |
| scout-setup.json run | end of `Scout_Hub_Setup::run()` | `setup` | "Business facts refreshed from the theme: team, hours, reviews" (only the sections that changed) | No |
| Change request opened | `Scout_Hub_Requests::handle()` after `wp_insert_post` | `request` | "You asked: " + request title. Ref = request ID. | Yes |
| Request status changed | same, `status` branch | `request` | "In progress: …" / "Done: …" | Yes |
| Scout Chat change request | Scout Chat saves it as a `scout_hub_request` when Scout Build is active (Scout Chat 0.3.0; keeps its own inbox copy, and changes nothing when Scout Build is absent) | `request` | as above, source "Scout Chat" | Yes |
| Hidden from Google alert sent | `Scout_Hub_Watch::check()` when it mails | `alert` | "Site hidden from Google; Scout alerted" | No |
| Site back in Google | `Scout_Hub_Watch::check()` when `hidden()` turns false after an alert | `alert` | "Site visible to Google again" | No |
| Approval recorded | the Approve action on Your site (step 3; the entry shape is fixed here) | `approval` | "Approved by Patrick McGuire: home page title wording" | Yes |
| A note | the Add a note form on Scout → Site log, or `POST` on the endpoint | `note` | whatever the person wrote, with Topic, Version, Measure and Show the client set on the form | As set |

Lines that need a person: hand edits in WordPress (a FAQ sentence Joy changed by hand), launch
steps, GBP and listing work, and anything done outside the site. Those are `note` lines. The 8:15
brief asks for them when a client repo moved and the site log did not.

## Reading it

### Scout → Site log (operators)

- Newest first. Filters: month, kind, topic, show the client, planned only.
- Each line: date, title, kind and topic pills, version, source, a check when the client sees it.
  Inline edit of **Client wording**, **Measure** and **Show the client** on any line (these are the
  only editable fields on a done line). Lines shown to the client with no client wording carry a
  quiet flag, "no client wording yet", so the monthly draft is never built from an internal title.
- **Add a note** at the top: Title, Client wording, Topic, Version, Measure, Status, Show the client.
- **Export CSV** of the current filter.
- **Import history** (operators only, hidden once used): takes one of the JSON files in
  `docs/site-log-import/`. Uses `import_id` to skip lines already in. Shows "N added, M skipped".

### The read endpoint (for the Sheet and for Claude)

Namespace `scout-build/v1`. Bearer token per site. Two tokens. Since 0.10.0 operators make them on
the Site log screen (shown once, stored encrypted like the theme key); `wp-config.php` constants are
an optional override that wins when set:

- `SCOUT_HUB_READ_TOKEN`: read only.
- `SCOUT_HUB_WRITE_TOKEN`: read, and `POST /log` for `note` lines only. This is Claude's key for
  logging work as it ships. It cannot change feature switches, requests or settings.

| Call | Returns |
|---|---|
| `GET /log?since=2026-10-01&client=1` | Lines since the date; `client=1` keeps only lines shown to the client and returns client wording in place of the title. Paged, 200 a page. |
| `GET /log?month=2026-10&client=1` | The month the monthly message is built from. |
| `GET /requests?status=received,progress` | Open requests: title, when asked, how soon, status, page. Never the attachment. |
| `GET /site` | Site name, home URL, live or staging, plan (The Build, The Partnership, The Whole System), WordPress and PHP versions, theme name and version, every Scout plugin and version with update waiting or not, the Site health summary lines, review totals and their last-updated date when Scout Reviews is on, spam blocked this month when Forms Guard is on. |
| `POST /log` | One `note` line. Body: title, client_text, topic, version, measure, status, show_client, source. Source is forced to "Claude" or "Sheet" by which token is used. |

Rules: 60 calls a minute per token; every refused call is logged as an `alert` line with the IP
left out; responses carry no visitor data, no form entries, no keys; the endpoint is off on
partner-side sites unless `SCOUT_HUB_LOG_API` is true.

### What reads it next (not in this release)

- **Step 2, the Sheet.** An Apps Script in Scout's Google Workspace calls `/site`, `/log` and
  `/requests` on each listed site nightly and writes the Sites, Log and Requests tabs. The Clients
  tab is typed by Joy and Sean (decision 4).
- **Step 3, Your site.** Built in 0.11.0: the same screen for non-operators, client lines only.
  Waiting on you (Approve), This month, Your requests, Coming up, Earlier this year. An operator
  puts something up for approval by adding a note with status waiting and Show the client on.
- **Step 4, the monthly message.** Built from `/log?month=…&client=1`, parked for three days as a
  draft, sent through Scout Email as team@scoutraleigh.com after Joy or Sean approve it.

## Importing the history

`docs/site-log-import/` holds one file per Scout WordPress site, generated from
`Scout-Internal/ops/site-updates.md` as it stood on 2026-11-02 (43 rows, 75 lines after the eight
"Every site" rows are copied to all five sites):

| File | Site | Lines | Client-visible |
|---|---|---|---|
| `andysgolfcarts.json` | andysgolfcarts.com | 20 | 13 |
| `maravelas.json` | maravelas.com | 16 | 9 |
| `northcrestrv.json` | northcrestrvpark.com | 13 | 8 |
| `hairbypatrick.json` | hairbypatrickmcguire.net | 13 | 8 |
| `scoutraleigh.json` | scoutraleigh.com | 13 | 8 |

Bliss Boogie (Squarespace) and Walter Lutz (not built) have no WordPress site and no file.

How the columns map:

| site-updates.md | Entry field | Note |
|---|---|---|
| Date | date | The seven undated rows were all created 2026-10-16 and carry that date. |
| Update | title | As written. |
| Notes | detail | Internal. Dollar figures were already left out of the source. |
| Type | topic | Setup, Speed, Plugin release, Email, Brand, Content, Launch, lower-cased. |
| Who | source | Joy, Claude, Sean, Client. |
| Status | status | Done becomes done. Waiting and To do both become planned (the Waiting rows are themes built and not deployed; they stay planned until the deploy logs its own line). |
| Version | version | As written. |
| (derived) | kind | plugin for Plugin release rows, theme for rows with a bare theme version, note for everything else. |
| (derived) | show_client | On only for Done rows of topic speed, brand, content, email or plugin. Setup and Launch rows stay internal. |
| (derived) | client_text | Empty on every line. These lines show their title to the client until Joy or Sean write the client wording, and the screen flags them. Writing 46 client sentences is Joy's pass after the import, not something to guess here. |

After the import: `ops/site-updates.md` gets a closing line pointing here and stops taking rows. The
"Updates log" lists in `ops/clients.md` stay as history and stop growing once the Sheet is live.

## Build and release

1. Branch from `main`, `scout-build` 0.9.0. Files: `includes/class-scout-hub-log.php` (post type,
   write helper `Scout_Hub_Log::add( array $entry ): int`, the hooks above, import), 
   `includes/class-scout-hub-log-api.php` (the endpoint), the Site log tab in
   `class-scout-hub-admin.php`. The Features screen keeps its own short switch log (the 30-row option);
   the Site log gets its line through the `scout_hub_feature_done` action, so nothing on that screen changes.
2. Scout Chat 0.3.0 in the same PR: save change requests as `scout_hub_request` when Scout Build
   is active.
3. `tools/core-contract.sh`: add `scout_sync_applied` (Scout Build listens for it; Scout Core must keep
   firing it with the same two arguments).
4. Changelog entry in `scout-build/CHANGELOG.md` and `scout-chat/CHANGELOG.md`; the catalog summary
   for Scout Build gains "keeps the site's change log".
5. Release and test on scoutraleigh.com first, then North Crest, then the rest (the hub spec's rule).
   On each site: run Import history once with that site's file, add the two tokens to `wp-config.php`,
   open Scout → Site log and check the imported lines, then make one plugin update and confirm it
   logged itself.
6. Sites with auto-updates off get 0.9.0 by hand. Check which ones on the Sites tab once it exists;
   until then, Maravela's and Patrick were off on 2026-10-16.

## Verify before building

- **Claude's reach.** Whether the cloud session can call a client site's endpoint and the Sheet. If
  the network policy says no, Claude logs through the repo and a GitHub Action posts the note. Same
  entry, one extra hop.
- **Theme CHANGELOG in the zip.** The theme line reads the theme's `CHANGELOG.md`. Confirm each
  client theme's release workflow (`tools/theme-release/`) ships it; if not, the line is version only.
- **Scout Chat's request shape.** Chat stores name, email and the ask. Confirm the bridge drops
  nothing the client expects to see on Your site.

## Left out on purpose

- WordPress logins, admin user changes and page edits by the client. Useful for security, noisy for a
  change log, and Scout Build 0.9.0 is about what Scout did. If wanted later, it is its own kind.
- Pushing to a Worker or any endpoint of ours. Sites answer when asked and send nothing.
- Anything about money. The hub has no money in it anywhere (decision 4 and `CLAUDE.md`).
