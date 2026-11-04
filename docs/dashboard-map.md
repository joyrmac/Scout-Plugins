# Scout dashboard map

Where every Scout screen and feature lives in a client's WordPress dashboard,
who sees it, and how to build a new one so it fits. Read this before adding a
plugin, a screen, a setting or a queue.

Status: v1.0, 2026-11-04. Decided by Joy (dashboard redesign session). Sean
reviews the open items at the bottom. Code: `tools/admin-kit/` (shared by every
plugin) and `scout-build/` (the hub). Design canvas: "Scout Build Dashboard"
artifact. Feature plan: "Scout Feature Lineup" artifact.

---

## The one rule: file by the job, not by the plugin

A client never thinks "which plugin is this in." They think "I need to change
my hours" or "someone sent me something." So every screen is filed by the job a
person came to do, and the menu stays the same on every site.

---

## The menu

| Group key | Label | Who sees it | What goes here | The test question |
|---|---|---|---|---|
| `overview` | Home | everyone | The first screen: what needs you today, site health at a glance, this month's numbers, shortcuts | Is this the first thing a person should see? |
| `inbox` | Inbox | everyone | Anything a person sent, or anything waiting on a person: chat questions, new reviews, change requests and their status, site check requests, booking requests | Did someone send this, or is it waiting on someone? |
| `reports` | Reports | owner, operator | Recon's numbers and reports (shows only when Recon is on) | Is this Recon data? |
| `business` | Your business | everyone | Facts typed once that show up everywhere: Business info, Hours, Team, Case results, a companion plugin's own content types | Is this a fact about the business? |
| `website` | Your website | owner, operator | What visitors see and use: Call buttons, Chat, Reviews on the site, Cards and links, Newsletter, Redirects | Is this something visitors see or use? |
| `search` | Get found | owner, operator | SEO, AI search, Analytics and the cookie notice, robots.txt, site search and the sitemap | Does this help Google, AI tools or visitors find things? |
| `policies` | Policies | owner, operator | Trust and legal pages reviewed every year: accessibility statement, law-firm disclaimers (Scout Law Firm Tools), and later the privacy policy and terms | Is this a trust or legal page? |
| `health` | Site health | owner, operator | Reports on whether things are working: the health report, Search health, Spam protection, the image optimizer, accessibility checks | Does this tell us if something is working? |
| `build` | Scout tools (Build tools on white-label sites) | operators only | Features and plan, Updates, Site log, Email, Chat settings, Customer list, Site Sync, the Change log, a theme's own screen | Is this only for Sean and Joy? |

Plus modules are not in this menu. Each one is its own sidebar section (see
"Plus sections" below).

Keys stay stable even when labels change, so filters keep working. The retired
key `content` maps to `website`.

---

## Where does a new screen go?

Ask these in order. The first yes wins.

1. **Is it a paid module that runs its own program** (bookings, events, members,
   sponsors, a writing room, custom content like RV sites)? Make it a **Plus
   section**: its own sidebar menu.
2. **Is it Recon data?** File it under **Reports**.
3. **Is it only for Sean and Joy** (switches, updates, logs, email setup, keys,
   integrations)? File it under **Scout tools**.
4. **Did someone send it, or is it waiting on a person?** File it under **Inbox**,
   and add its count with the `scout_hub_inbox` filter.
5. **Is it a fact about the business, typed once?** File it under **Your business**.
6. **Is it something visitors see or use on the site?** File it under **Your website**.
7. **Does it help Google, AI tools or visitors find things?** File it under **Get found**.
8. **Is it a trust or legal page that gets a yearly review?** File it under **Policies**.
9. **Does it report whether something is working?** File it under **Site health**.
10. **Still unsure?** File it under **Your business** and ask Joy.

A screen can hold settings and a list together (a settings screen with its
list beside it). Split a screen only when the two halves are for different
people, like a staff list and an operator setting.

---

## How filing works in code

- `Scout_Admin_UI::group( $slug, $label )` in `tools/admin-kit/class-scout-admin-ui.php`
  files a screen by its admin slug. Add a slug pattern there for a new kind of screen.
- A plugin can file one of its screens with the `scout_admin_nav_group` filter
  (`$group, $slug, $label`). Prefer a slug pattern in the kit when the rule is general.
- **Every new screen gets a line in `tools/tests/admin-kit-groups.php`.** The
  Lint workflow fails when the filing drifts from this map.
- **A screen never changes its address.** Only where it is filed changes, so
  bookmarks and links keep working.
- **The kit ships in every plugin.** After changing `tools/admin-kit/`, copy it
  into every plugin (`*/includes/class-scout-admin-ui.php`, `*/assets/scout-admin.css`),
  bump every plugin's patch version and add a changelog line. CI checks the copies match.
- Group labels come from `Scout_Admin_UI::label()`, never from `GROUPS` directly,
  so white-label sites read the right words.

---

## The four kinds of features

| Kind | Icon | What it means | In the dashboard | Catalog tier |
|---|---|---|---|---|
| **Core** | Bolt (only on the Core heading, never on each row) | On every site, every plan, including Friends and family | No label. It's simply how the site works | `build` |
| **Bonus** | Green diamond | Free extras we love, added when they fit the client | A small "Bonus from Scout" tag on its screen | `bonus` (`addon` still reads as Bonus) |
| **Plus** | Purple plus sign | Paid modules we build, added one at a time | Its own sidebar section with a Plus tag | `plus` |
| **Recon** | Amber bar chart | Its own product, for reporting and proof | A Reports item, plus the monthly numbers on Home | `whole` / `recon` (planned) |

In code (`Scout_Hub_Access`, Scout Build 0.12.0): `included( $tier, $slug )`
answers whether a feature is part of the site's plan, `picks()` holds features
added to a site one at a time (and, on Friends and family, the theme setup
file's "use" list), and `recommended()` feeds More features on Home.

Plan features (Reviews and review requests, Newsletter) come with The
Partnership and up. Friends and family is Core plus anything picked by hand.

Decided by Joy, 2026-11-04:

- **Basic chat is a Bonus.** AI answers come with The Partnership, or as a Plus add-on.
- **The Whole System does not include Plus modules.** Plus is always added on its own.
- **Monthly numbers on Home:** a small Recon snapshot on The Partnership, full
  Recon dashboards and monthly reporting on The Whole System. The Build shows no numbers.
- **Policy pages:** Scout provides privacy policy and terms templates filled from
  business info, and the client or firm approves each one. They are never legal advice.
- **Form entries delete themselves after 90 days** by default on law-firm sites.
- **Focus mode stays on Home.** It hides the numbers on busy days.
- **Recon on client sites is a viewer.** Accounts, settings and profiles stay on
  scoutrecon.app; the plugin only pulls that client's reports.
- **Law-firm pieces are Core for law sites**, built in this order: disclaimers
  (Scout Law Firm Tools 0.1.0), approval before publishing, "Reviewed by"
  stamps with a six-month review list, then form entries that delete themselves.
Shape and color always travel together, so the kinds can be told apart without
color.

---

## Who sees what

| Person | How we know | Sees |
|---|---|---|
| Operator | Listed in `SCOUT_HUB_OPERATORS` and a WordPress admin | Everything, including Scout tools and the greyed "Turn on" items |
| Owner | Can change site settings (`manage_options`) | Everything except Scout tools |
| Staff | Cannot change site settings | Home, Inbox, Your business, and any Plus section they work in |

`Scout_Admin_UI::hidden()` decides this, and the `scout_admin_hidden_groups`
filter adjusts it. Hiding a group never replaces a capability check: every
screen and every action still checks the person's rights itself.

Scout team logins never appear on a client's Team page or as a blog author.

---

## Plus sections

A Plus module gets its own sidebar section, directly under the Scout menu
(menu position `3.x`), and only on sites that have it.

- The kit tags the section with a small "+ Plus" label. Add the module's menu slug to
  `Scout_Admin_UI::PLUS_MENUS`, or use the `scout_admin_plus_menus` filter for a client's own module.
- The section's first screen is its own overview: what's waiting, what's coming
  up, and the one thing to do next.
- Its screens use the admin kit, so they look and behave like the rest of Scout.
- Anything a person sent through it (a booking, an application, a sign-up) also
  adds a count to the Inbox with `scout_hub_inbox`.
- The customer list goes with the Plus module that collects people.
- References: `scout-events`, `scout-members`, `scout-booking`, `scout-partners`,
  `scout-writing`.

---

## Building a screen: the one layout

Every Scout screen follows the same order, so nobody has to relearn a page.

1. `Scout_Admin_UI::open( $title, $lede )`: the title, then one plain sentence
   on what this screen is for. Add a link to a one-minute guide when there is one.
2. A status line in words and a shape: On, Needs you, or Off.
3. The main content in `.sh-box` sections, with one primary button on the screen.
4. Rarely used settings go in a closed "More options" section.
5. A side box called "Where this shows," listing every place on the site this setting changes.
6. Save on its own where it can, show "All changes saved," and offer Undo.
7. `Scout_Admin_UI::close()`.

---

## UX rules (built for ADHD and autistic brains, good for everyone)

- At most seven groups in the menu. Never tabs inside tabs.
- One primary action per screen. Everything else is secondary or a link.
- Every task on Home shows about how long it takes ("About 3 min").
- Show progress and finish lines: "1 of 4 done," and a designed "All caught up" screen.
- Status is always a word plus a shape, never color alone.
- At most two or three items across. Never four across.
- No orphans or widows in headings, buttons or list items.
- Plain words at about an 8th grade reading level, with contractions. No exclamation points.
- No dead links for clients: features that are off stay out of their menu.
  Recommended features sit in a closed "More features" dropdown.
- Help lives on the screen: one "What's this?" line, never a separate help hunt.
- Let people choose how often they get emails: once a day, every message, or never.
- Touch targets at least 44px. Text at least 16px. Contrast at least 4.5:1.

---

## Home and Today

- **Needs you** reads the same rows as the Inbox (`Scout_Hub_Admin::waiting_on_you()`
  and the `scout_hub_inbox` filter).
- **Today** (`Scout_Hub_Today`, Scout Build 0.12.0) turns those rows, failing
  health checks and a site hidden from Google into a numbered list. Any plugin
  adds an item with the `scout_hub_today` filter: `id`, `label`, `why`,
  `minutes`, `url`, `button`, `priority` (lower first) and `cap` (who sees it).
  Law-firm approvals and pages due for review should arrive this way.
- "Not today" hides an item until tomorrow. Progress counts what left the list
  earlier today. An empty list shows "Nothing needs you today."
- **The first-week setup list** shows owners five steps above Today until they
  are done (`scout_hub_setup_steps` adjusts them). Operators can hide it.

---

## The Inbox

`Scout_Hub_Inbox` lists every item waiting on a person, newest first, with
filter chips by kind (chat, reviews, requests, bookings, site checks, other).
A plugin adds items with the `scout_hub_inbox_items` filter: `id`, `kind`,
`label`, `from`, `preview`, `when` (timestamp), `url` and `waiting`. Count rows
from `scout_hub_inbox` still drive Home and the sidebar bubble, and any row
without its own items shows as one item. Each person picks Inbox emails:
never (the default), once a day, or for every new item.

## White-label and partner sites

When `SCOUT_HUB_BRAND` is false, the menu says Site Build, Scout tools says
Build tools, and no screen mentions Scout. Messages from us are signed "Your
web team." Confirm labels with the partner agency before installing anything
there.

---

## Recon on client sites (side project, with Sean)

Recon runs on its own site, `scoutrecon.app`. On a client site it should be a
**viewer**: a small plugin that pulls that client's reports from Recon and
shows them under Reports, with the monthly snapshot on Home. Accounts,
settings, profile updates and connections stay on the Recon site. Open
questions for Sean: how a site proves which Recon account it belongs to (a
per-site key), what Recon sends (a summary or full report pages), and how
often it refreshes.

---

## Adding a feature: the checklist

- [ ] Pick its kind (Core, Bonus, Plus or Recon) and its catalog tier.
- [ ] Pick its group with the questions above, or make it a Plus section.
- [ ] Add its slug to `tools/tests/admin-kit-groups.php`, and a kit pattern if needed.
- [ ] Use the one layout and the UX rules.
- [ ] Check what owners, staff and operators see.
- [ ] Check it on a white-label site.
- [ ] If it waits on a person, add its count to the Inbox.
- [ ] Bump the version, write the changelog line and update the plugin README.

---

## Build queue (Joy, 2026-11-04)

Two chats build this at once. **The dashboard chat** owns the admin kit
(`tools/admin-kit/`), Scout Build's Home, Inbox, Features and plan screens,
and the menu. **The features chat** owns the feature plugins and only files
its screens with the `scout_admin_nav_group` filter, never by editing the kit.
Each works on its own branch; merge one, then rebase the other.

**Dashboard chat**

1. The new menu (Scout Build 0.12.0, admin kit 1.8.0). Built; waiting on Joy to merge.
2. Home: the Today list (a `scout_hub_today` filter), all caught up, the first-week setup list. Built in Scout Build 0.12.0.
3. Inbox: one list for chat, reviews, requests and bookings, with filters and the email-me setting. Built in Scout Build 0.12.0.
4. Plans: Friends and family, the Bonus and Plus catalog tiers, recommended features, and More features on Home. Built in Scout Build 0.12.0.
5. Plus sections: the Plus tag (admin kit 1.8.0, `scout_admin_plus_menus`). Each module already opens on its own overview screen.
6. Team and My profile screens.
7. Bonus: "Not today" on Today items, comfort settings (larger text, less motion, dark mode), "Preview on a phone," and the "What's new on your site" card.

**Features chat**

1. Law firm tools: check approval before publishing (0.2.0) in a live block editor, then "Reviewed by" stamps with a six-month review list, then form entries that delete themselves (90 days by default).
2. Search and sitemap: a visitor search box on every site, and a list of searches that found nothing.
3. One profile per person: Scout Cards, chat photos and "Reviewed by" stamps read from Team.
4. Nightly form test in Site health.
5. Policies pages: privacy policy and terms templates filled from business info. The client or firm approves each one.
6. One screen for every client site, built on the Client Hub sheet.
7. Recon as a viewer plugin (the viewer approach is decided; the connection details wait on Sean).

## Open items for Sean

- How Recon installs as a viewer: how a site proves which Recon account it
  belongs to, what Recon sends (a summary or full report pages), and how often
  it refreshes. Joy decided the viewer approach on 2026-11-04.
