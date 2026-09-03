# Scout Fleet Matrix

The one place that shows the whole roster: every site, what it runs, how it
updates today, and who has access. The platform map
(`Scout-Media-Raleigh/ops/playbook/platform-map.md`) reads from this file;
update it whenever a site deploys, a plugin updates, or a client joins or
leaves. Fields marked **TODO** need Joy or Sean to fill from the live installs.

Last verified: 2026-09-03 (Plugins + Updates screenshots from all five
installs; remaining TODOs need the WP Engine portal and account answers).

---

## The roster

| Site | Domain | WP Engine install | Repo | Tier |
|---|---|---|---|---|
| Scout Media | scoutraleigh.com | **TODO** | `joyrmac/Scout-Media-Raleigh` | own site |
| Scout Recon | scoutrecon.app | `scoutrecon1` | `joyrmac/Scout-Media-Raleigh` (`wordpress/recon-deploy/`, separation plan in `ops/`) | own product |
| Hair by Patrick McGuire | **TODO** (live domain not launched; staging at hairbypatrick.wpenginepowered.com) | `hairbypatrick` (staging: indexing blocked in code) | **TODO** | **TODO** |
| Maravela's Banquets & Catering | **TODO** | **TODO** | **TODO** | retained client |
| North Crest RV Park | northcrestrvpark.com | `rlcnorthcrestr` (Prd) | **TODO** | **TODO** |

## Versions

All five installs run WP Engine Smart Plugin Manager 6.1.8 as the vendor
update layer (most plugins "Managed by" it, auto-updates otherwise largely
disabled).

| Site | Theme (version) | WordPress | scout-core | scout-forms-guard | scout-optimize | Gravity Forms | Third-party beyond GF |
|---|---|---|---|---|---|---|---|
| Scout Media | scout-media 1.21.6 | **TODO** | not installed (theme owns SEO/schema/setup) | 0.1.0 | **0.4.0 (behind 0.6.0)** + Pilot Toggle 1.1.0 (temp, delete after pilot) | 3.1.0.2 + reCAPTCHA add-on 2.2.2 | WordPress Importer (inactive-ok) |
| Scout Recon | **TODO** | **TODO** | none (no Scout plugins at all) | none | none | 3.1.0.2 | Akismet 5.7.2, Genesis Blocks 3.1.11, WordPress Importer |
| Hair by Patrick | HBP theme 0.9.12 | 7.1 | **1.0.4 ✓** | **0.1.1 ✓** | **0.7.0 ✓** | **2.8.11 INACTIVE (deactivated over WP Engine security-risk flag; forms dead until licensed + updated + reactivated). Launch blocker.** | Akismet 5.7.2 (active — remove). On the auto-update channel 2026-09-03. |
| Maravela's | Maravela's theme **TODO** | current | **1.0.4 ✓** | **0.1.1 ✓** | **0.7.0 ✓ (active; keep the delivery toggles off — see drift item on the hero)** | **3.1.1 ✓** | Genesis Blocks Pro 3.1.11 (active), WordPress Importer (active). On the auto-update channel 2026-09-03. |
| North Crest | north-crest 3.11.1 | 7.0.4 | **1.0.4 ✓** (1.0.3 fataled, fixed same day) | **0.1.1 ✓** | **0.7.0 ✓** | 3.1.0.2 | GTM4WP 2.0.0 (2.0.1 pending), Smush 4.3.2 (inactive, delete); scout-rvpark **0.1.1 ✓**. **All Scout plugins self-updating as of 2026-09-03 — first site fully on the channel.** |

**Auto-update channel: LIVE as of 2026-09-03.** Published releases:
scout-forms-guard **0.1.1**, scout-optimize **0.7.0**, scout-rvpark **0.1.1**.
**scout-core 1.0.4 still needs publishing** (fix merged; run Actions → Release
→ scout-core) **and the broken 1.0.3 release needs deleting.** Any site on
current versions installs every future release automatically (twice-daily
check).

## How each site updates today (to be replaced by Site Sync)

| Site | Mechanism | Blueprint state |
|---|---|---|
| Scout Media | Copy hardcoded in PHP templates; one-click setup + Tools → Scout Build; deploy = theme zip via `/deploy-wordpress` | pre-blueprint |
| Hair by Patrick | Scout dashboard: char-count freshness, re-sync from theme, seeders, SEO filler | pre-blueprint, closest to Site Sync |
| Maravela's | Tools importer: bundled HTML → `wp:html` pages, dry run / force overwrite, identity reset, seeders | pre-blueprint |
| North Crest | Theme Dashboard: status, re-run setup, content map (copy edited in repo, deployed); defers to Scout plugins | pre-blueprint, model status-only site |

## Access register (pointers only, never secrets)

Where credentials live, not what they are. **TODO: fill and confirm 2FA per row.**

| What | Who has it | Where stored | 2FA |
|---|---|---|---|
| WP Engine account | **TODO** | **TODO** | **TODO** |
| wp-admin per site | **TODO** | **TODO** | **TODO** |
| Domains / DNS registrar(s) | **TODO** | **TODO** | **TODO** |
| GitHub org/repos | Joy, Sean | github.com/joyrmac | **TODO** |
| Google (GA4, GSC, GBP) per client | **TODO** | **TODO** | **TODO** |
| Gravity Forms license | **TODO** | **TODO** | **TODO** |

## Deploy pipeline groundwork

WP Engine **GitPush is available on our plan** (verified on `rlcnorthcrestr`,
2026-09-03: git remote `git@git.wpengine.com:rlcnorthcrestr.git`, SSH key per
developer). That is the future theme-deploy path (push to deploy instead of
zip uploads). Division of labor stays clean: **themes deploy via GitPush,
plugins update themselves via GitHub releases**; never manage the same
directory both ways.

## Rituals

- **Monthly update pass:** walk the Versions table, update WordPress and Scout
  plugins on every site, re-verify, stamp "Last verified."
- **Every deploy:** update the deployed theme version here, same commit or
  same day.
- **Every WordPress major:** regression smoke check per site (spec pending),
  then the update pass.
- **The two platform numbers** (start tracking, even roughly): hours to launch
  a new site, and minutes per site per month to maintain. Falling numbers mean
  the platform is working.

## Known drift to fix (as of 2026-09-03, from the Plugins pass)

Ordered by risk:

1. **scout-core 1.0.0 on all three client sites** (HBP, Maravela's, North
   Crest); 1.0.2 fixes an uninstall data-loss bug. Root cause found: **no
   1.0.x release was ever published**; the newest GitHub release of every
   plugin is 0.1.0 (2026-08-13), so even a working updater had nothing newer
   to find. The fix is one pass: publish current releases (Actions → Release
   per plugin), then manually upload fresh zips of all Scout plugins to each
   site once (deployed 1.0.0/0.1.0 copies predate the working updater). From
   1.0.3 / 0.1.1 on, releases install themselves automatically.
2. **HBP: Gravity Forms 2.8.11 with an unregistered license.** Old version and
   no update channel on a form the launch depends on. Register the license and
   update before launch; this is a launch blocker.
3. **HBP: Akismet active.** Architecture says scout-forms-guard replaces it
   (no third-party calls). Remove before launch; also resolves the Akismet
   setup nag.
4. **Scout Media: 3 pages from a theme update missing** (seo-content,
   website-security, local-seo); the admin notice offers Run Scout setup. Run
   it on the next admin visit.
5. **Scout Media: scout-optimize 0.4.0** while clients run 0.6.0; update, and
   delete the Pilot Toggle helper when the pilot is done.
6. **Scout Media: Gravity Forms reCAPTCHA add-on** contradicts the
   forms-guard approach (no reCAPTCHA). Confirm which form still uses it, then
   retire it or record the deviation.
7. **Maravela's: hero images 404 via relative srcset.** The homepage hero
   slides carry `srcset="assets/photos/..."` (relative, from the static
   build) while `src` is absolute; browsers pick srcset and 404, blanking
   the hero. Hotfixed by stripping srcset/sizes on the Home page in wp-admin
   (2026-09-03); the permanent fix is in the theme's bundled page source,
   pending access to the Maravela's theme repo. Scout Optimize was ruled
   out (no picture rewriting in the served page).
8. **Maravela's: Genesis Blocks Pro active** (third-party page builder beyond
   the Gravity-Forms-only rule); its pages import as `wp:html`, so audit what
   actually depends on it, then replace or record the deviation. Also:
   scout-optimize installed but inactive (activate or remove) and a WordPress
   update pending.
9. **North Crest: GTM4WP active** (third-party; likely deliberate for Tag
   Manager — record it in that repo's `decisions.md` or replace with a theme
   snippet) and **Smush inactive** (redundant with scout-optimize; delete).
10. **Scout Recon runs no Scout plugins** and carries Akismet + Genesis Blocks.
   Its stack is its own product decision; record it deliberately rather than
   by accident.
11. **Scout Media runs no scout-core**; the theme owns SEO/schema/setup.
    Acceptable until Site Sync, then converge.
