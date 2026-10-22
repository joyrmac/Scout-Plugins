# Site Sync — spec (scout-core module)

One updating system for every Scout site. Today each theme carries its own
homegrown version of the same job, and this module replaces all of them with
one shared engine in `scout-core` plus a small declarative contract the theme
ships. Status: **phase 1 built, not released** (2026-10-22, see "Phase 1 as
built" below). Owner: Sean and Joy.

---

## The problem this solves

Four live sites, four different updating styles, all hand-built into their
themes:

| Site | Mechanism today | Where it lives |
|---|---|---|
| Scout Media | One-click setup + Tools → Scout Build (WXR and form import checklist); copy hardcoded in PHP templates | `scout-media/inc/setup.php`, `inc/build-tools.php` |
| Hair by Patrick | Scout dashboard: status table, page-content freshness (theme copy vs DB by character count), re-sync button, seeders, SEO description filler | the HBP theme |
| Maravela's | Tools page importer: bundled static HTML imported as `wp:html` blocks, dry run, force overwrite, identity reset, seeders, category backfill | the Maravela's theme |
| North Crest | Theme Dashboard: status and health, re-run setup, content map (page to template file, copy edited in the repo then deployed), integrations list deferring to the Scout plugins | the North Crest theme |

Every improvement is currently made three times or not at all, which is exactly
the per-client fork the architecture doc says will sink the platform. The sync
machinery is functionality, and functionality lives in plugins.

## The target

- **One dashboard.** Every site gets the same top-level Scout screen, rendered
  by scout-core: status, freshness table, actions. Same UI, same buttons, same
  behavior on every client site.
- **One contract.** The theme stops carrying sync code and instead ships a
  manifest declaring its bundled content. scout-core does the rest.
- **Freshness you can trust, updated automatically.** On every theme update the
  engine re-checks freshness, auto-syncs pages that are safe to sync, and flags
  the rest with an admin notice naming exactly what needs review.

## The theme contract

The theme ships a `scout-sync.json` manifest at its root plus a `sync/` content
directory. The manifest declares, per entry, everything the engine needs:

```json
{
  "sync_version": 1,
  "identity": {
    "title": "Maravela's Banquets & Catering",
    "tagline": "Family-owned banquet hall in Ingleside, IL since 1982.",
    "logo": "sync/identity/wordmark-black.png",
    "icon": "sync/identity/brandmark-black.png",
    "posts_page": "blog"
  },
  "pages": [
    { "slug": "home", "title": "Home", "template": "front-page",
      "source": "sync/pages/home.html", "front_page": true },
    { "slug": "services/weddings", "title": "Weddings",
      "source": "sync/pages/services-weddings.html" }
  ],
  "seeds": [
    { "type": "testimonial", "source": "sync/seeds/testimonials.json" },
    { "type": "faq", "source": "sync/seeds/faqs.json" }
  ],
  "seo": { "source": "sync/seo/descriptions.json" }
}
```

Rules of the contract:

- The theme's version header is the sync version. A version bump is what
  triggers a re-check.
- Sources are plain files in the theme (HTML for page content, JSON for seeds
  and SEO), generated from the client repo the same way themes are today.
- A theme with no manifest gets the dashboard in status-only mode. Nothing
  breaks on day one.

## The freshness engine (hashes, not character counts)

The HBP dashboard compares character counts, so two different texts of the same
length read as in sync. The engine compares content hashes instead, and it
keeps a third value that makes automatic decisions safe:

- **theme**: hash of the copy bundled in the currently installed theme.
- **db**: hash of what the WordPress page holds right now.
- **base**: hash of the theme copy at the moment of the last sync, stored in
  post meta when the engine writes a page.

That third value turns two-way guessing into a three-way decision:

| theme vs base | db vs base | State | Action |
|---|---|---|---|
| same | same | In sync | none |
| changed | same | Stale, untouched in WP | **auto-sync** on theme update |
| same | changed | Edited in WordPress | flag: flow the edit back to the repo (`align-content`) |
| changed | changed | Conflict | flag: needs review, never auto-overwritten |
| no base recorded | — | Unmanaged or pre-sync page | import path (dry run first) |

The standing rule: **the engine never silently overwrites a hand edit.** Auto
means automatic only for pages WordPress has not touched since the last sync.
Everything else becomes a named row in the dashboard and an admin notice
("2 pages need review"), with a per-page diff view and explicit buttons for
"take theme copy" and "keep WordPress copy".

## Automatic runs

- On `after_switch_theme` and on theme-version change (checked on `admin_init`
  against a stored option): run setup (idempotent, never overwrites), then run
  the freshness pass, auto-sync the safe pages, and post the review notice for
  the rest.
- Every action the dashboard offers also runs headless via WP-CLI
  (`wp scout sync status|run|import --dry-run`), which is what makes fleet-wide
  updates scriptable later.

## Section-level freshness (phase 3)

Whole-page states say that something changed, not what. Bundled page sources
gain section markers (`<!-- scout:section hero -->` ... `<!-- /scout:section -->`),
the engine hashes per section, and the freshness table expands a stale page to
show exactly which sections moved. The three-way rule applies per section, so a
theme update to the hero can auto-sync even when the FAQ section carries a
WordPress-side edit.

## What each site does to adopt it

| Site | Migration |
|---|---|
| Hair by Patrick | Closest to target. Port its dashboard into scout-core, replace char counts with hashes, generate the manifest from its existing bundled copy, delete the theme's own sync code. |
| Maravela's | Its importer becomes the same manifest (pages as HTML sources, seeds, identity block). Dry run and force overwrite survive as engine features for the unmanaged-page path. |
| Scout Media | Adopts the dashboard in status-only mode first (its copy is hardcoded in PHP templates, so there is nothing to diff). Pages move under sync management as copy migrates per the update-path rule in the architecture doc. |
| North Crest | Already the model status-only site: scout-core active, dashboard defers to the plugins, content map names each page's template file. Its dashboard becomes the shared status-only UI; the content map and integrations panels are worth porting into the engine for every site. |

## Build order

1. **Engine + dashboard in scout-core**: manifest reader, hash freshness with
   stored base, manual re-sync, seeders, identity, SEO filler. HBP is the pilot.
2. **Automation**: version-change trigger, auto-sync of safe pages, review
   notices, WP-CLI commands.
3. **Section markers** and per-section freshness.
4. **Retire per-theme code** on all three sites; new builds ship manifest-only.

## Relationship to the rest of the platform

- This is the productized form of the "theme bundles copy, WordPress holds the
  live page" pattern. It complements, and does not replace, the update-path
  rule in `ops/playbook/in-house-architecture.md` §5: content on the field and
  CPT paths never goes through sync at all.
- `scout-importer` (Model B, WP-CLI bulk import from git files) stays a
  separate concern; Site Sync may share its readers but serves the theme-update
  loop, not migrations.
- Per-client extras (Maravela's category backfill, one-off fixups) live in that
  client's companion plugin as extra dashboard actions, registered through a
  scout-core hook, so the shared engine never grows client-specific code.

## Phase 1 as built (2026-10-22, unreleased)

Code: `includes/sync/` (manifest reader, engine, the Scout > Site Sync tab).
Manual only: nothing runs on a theme update yet (that is phase 2).

- **States** match the table above, plus: *missing* (create it), *matches, not
  tracked yet* (start tracking, no write), *theme copy missing* (manifest
  names a file the theme does not ship).
- **Sync safe pages** creates missing pages, writes stale pages, and starts
  tracking identical ones. Edited, conflict, and untracked-but-different
  pages are never touched by it.
- **Per page:** Compare (side-by-side diff, changed lines only), Take theme
  copy, Keep WordPress copy, Undo last sync. Every write saves the old body
  first (`_scout_sync_snapshot`), so Undo is one click.
- **Keep WordPress copy** marks the base `kept`. Until the repo catches up,
  any later theme copy for that page is a conflict, never an auto-sync, so a
  kept edit cannot be overwritten silently on the next update.
- **Identical text is always in sync**, whatever the base says; Sync safe
  pages then refreshes the base so the next change compares against today.
- **The db hash is read back after each write**, so anything WordPress
  changes on save (filters, kses) never reads as a hand edit.
- **Seeds** rows: `title` (required), `content`, `excerpt`, `fields` (Scout
  field key => value). Skips any title already used by that type.
- **SEO** file: `{ "slug": { "title": "...", "description": "..." } }`.
  Fills blanks only; anything written in a Scout SEO box wins.
- **Identity:** title and tagline set to the theme's; front and posts page
  pointed at manifest pages; logo and icon added only when the site has none,
  imported to the media library once.
- **Safety:** every source path must resolve inside the theme folder; a
  traversal or missing file becomes an error row, never a read. All actions
  are `manage_options`, nonce-checked, and post-redirect-get.
- **Status only:** a theme with no manifest shows one line and changes nothing.
- **Dashboard card:** "Page content" joins the Scout health check when the
  theme ships a manifest.

Tested 2026-10-22 on WordPress 7.1.3 against a fixture built from Hair by
Patrick's 20 real pages, 14 testimonials and 41 FAQs (52 checks, all pass).

**Before the first release:** decide the release version (1.2.0), write the
HBP manifest generator in the HBP repo, and add the theme's `scout-sync.json`
there. The HBP theme keeps its own dashboard until the pilot proves out.
