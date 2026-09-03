# Scout Fleet Matrix

The one place that shows the whole roster: every site, what it runs, how it
updates today, and who has access. The platform map
(`Scout-Media-Raleigh/ops/playbook/platform-map.md`) reads from this file;
update it whenever a site deploys, a plugin updates, or a client joins or
leaves. Fields marked **TODO** need Joy or Sean to fill from the live installs.

Last verified: 2026-09-03 (versions from wp-admin screenshots and repo files;
everything else pending a fill-in pass).

---

## The roster

| Site | Domain | WP Engine install | Repo | Tier |
|---|---|---|---|---|
| Scout Media | scoutraleigh.com | **TODO** | `joyrmac/Scout-Media-Raleigh` | own site |
| Scout Recon | scoutrecon.app | `scoutrecon1` | `joyrmac/Scout-Media-Raleigh` (`wordpress/recon-deploy/`, separation plan in `ops/`) | own product |
| Hair by Patrick McGuire | **TODO** | **TODO** (staging: indexing blocked in code) | **TODO** | **TODO** |
| Maravela's Banquets & Catering | **TODO** | **TODO** | **TODO** | retained client |
| North Crest RV Park | **TODO** | **TODO** | **TODO** | **TODO** |

## Versions

| Site | Theme (version) | WordPress | scout-core | Other plugins | Updater active? |
|---|---|---|---|---|---|
| Scout Media | scout-media 1.21.6 | **TODO** | not installed (theme owns SEO/schema/setup) | Gravity Forms **TODO** | n/a |
| Scout Recon | recon (static deploy) **TODO** | **TODO** | **TODO** | **TODO** | **TODO** |
| Hair by Patrick | HBP theme 0.9.12 | 7.1 | **TODO** (Scout menu present) | Gravity Forms **TODO** | **TODO** |
| Maravela's | Maravela's theme **TODO** | update pending (7.1 offered) | **TODO** (Scout menu present) | Genesis Blocks (flag: third-party, per architecture only Gravity Forms is allowed), Gravity Forms **TODO** | **TODO** |
| North Crest | north-crest 3.11.1 | 7.0.4 | **1.0.0 (latest is 1.0.2 — update due)** | scout-optimize, Gravity Forms + scout-forms-guard | **TODO** |

Latest platform releases (this repo): scout-core **1.0.2**, scout-forms-guard
**0.1.0**. Any site below these is drift; close it on the next update pass.

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

## Known drift to fix (as of 2026-09-03)

1. North Crest runs scout-core 1.0.0; 1.0.2 fixes an uninstall data-loss bug.
   Update at the next touch (needs the one manual upload noted in the 1.0.1
   changelog).
2. Maravela's carries Genesis Blocks, outside the one-third-party-plugin rule.
   Decide: replace its blocks or record the deviation in that repo's
   `decisions.md`.
3. Maravela's has a WordPress update pending.
4. Scout Media runs no scout plugins; its theme carries SEO/schema/setup
   itself. Acceptable until Site Sync, then converge.
