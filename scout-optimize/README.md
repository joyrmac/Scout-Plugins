# scout-optimize

Scout-owned media optimization plugin. Private — installed only on sites Scout
hosts and maintains. Part of the Scout plugin suite (scout-core, scout-schema,
scout-seo, scout-forms-guard).

**Scope source of truth:** `scout-optimize-plugin-vision.md` in the Scout Media
project. **Build plan:** `scout-optimize-build-plan.md` (7 stages, A-G,
operator-gated).

> **Interim home (operator ruling, 2026-07-22).** This plugin currently lives at
> `platform/scout-optimize/` inside the Scout-Media-Raleigh monorepo, alongside
> the rest of the Scout plugin suite. Before Stage G (updates via
> plugin-update-checker and private GitHub releases), it graduates to a dedicated
> private repo, or to a release pipeline that packages it from this monorepo. Its
> CI workflow is wired at the repo root (`.github/workflows/ci.yml`) and scoped to
> this directory so it runs today.

## Current state: Stage E + AVIF and full-page delivery (0.6.0)

Uploads (Stage C), next-gen delivery (Stage D), and the existing-library sweep
(Stage E) are all built, now with AVIF twins and full-page delivery coverage
(0.6.0). Every path is gated by OFF-by-default settings, and the sweep only runs
when an admin starts it, so activating the plugin still changes nothing on its
own.

- **Uploads** (`optimize_uploads`): a new image is backed up (original preserved
  first), then its served full-size plus generated sub-sizes are optimized in
  place, with a per-attachment `_scout_optimize` summary. Full-size images are
  capped at a configurable longest edge (default 1920px) and PNGs are
  palette-quantized (0.3.1).
- **Twins** (`generate_webp`, `generate_avif`): the pipeline and sweep write a
  `.webp` and, when enabled and the server can encode it, an `.avif` twin next to
  each served file. A twin is kept only when it is smaller than the served
  primary. On real Scout images AVIF ran ~20% smaller than WebP (up to 27% on
  photographs).
- **Delivery** (`rewrite_picture`): front-end `<img>` tags whose twins exist are
  wrapped in a `<picture>`, with an AVIF `<source>` offered ahead of WebP and the
  original `<img>` kept as the fallback. Beyond the content and attachment-image
  filters, a `template_redirect` output-buffer pass rewrites the whole rendered
  page, so page-builder (Elementor) widgets, galleries, and template markup are
  covered too, not just `the_content`. Read-only markup; admin, feed, and REST
  output are never touched, and anything already inside a `<picture>` is skipped.
- **Sweep** (Tools → Scout Optimize Sweep): a confirmation-gated, background
  WP-Cron job that optimizes the existing library in time-boxed batches,
  backing up each original first. Existing dimensions are left untouched.

- Engine design and ratified decisions: `docs/stage-b-engine-spec.md`.
- Turning it on for a pilot: `docs/enable-optimization-on-staging.md`.

## Stage A audit (completed 2026-07-23)

On a WP Engine **staging** environment:

```
wp eval-file tools/audit-environment.php
```

Read-only. Reports Imagick/WebP capability, PHP limits, library size, sweep
candidates, and disk headroom for originals preservation. The staging audit
confirmed Imagick 7 with WebP encode, so the v1 Imagick engine path is cleared.
Note: WP Engine disables `disk_free_space`, so confirm the storage allowance
from the portal before the Stage E batch sweep.

## Development

```
composer install          # dev tooling (PHPCS/WPCS, PHPUnit)
composer lint             # WordPress Coding Standards
composer test             # PHPUnit (engine tests need the imagick extension)
npx @wordpress/env start  # disposable WordPress with the plugin mounted
```

## Invariants (do not violate in any stage)

1. Original images are never destroyed by any automatic code path. The only
   deletion surface is the Stage F purge tool: explicit, confirmation-gated.
2. One shared versioned plugin across the roster — never forked per client.
3. Clients never see quality controls; all admin surfaces sit behind the
   `manage_scout_optimize` capability.
