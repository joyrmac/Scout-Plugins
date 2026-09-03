# Changelog — scout-optimize

All notable changes to this plugin. Format: date, what changed, why, which files.

## [0.7.0] — 2026-09-03 (joins the self-updating suite)

- **What:** Graduated from the Scout-Media-Raleigh monorepo into Scout-Plugins,
  the anticipated Stage G move. Carries the shared Scout updater: the plugin now
  checks this repo's GitHub releases and installs updates automatically on
  WordPress's twice-daily check. Opt a site out with SCOUT_DISABLE_AUTO_UPDATES
  in wp-config.php. No optimization behavior changed.
- **Provenance note:** the 0.6.0 source was recovered from the deployed
  production copy (the build shipped without its source landing in git); this
  release re-establishes the repo as the source of truth.

## [0.6.0] — 2026-07-24 (AVIF twins + full-page delivery coverage)

- **What:** Two next-gen delivery improvements, both gated OFF by default.
  1. **AVIF twins.** The engine now writes an `.avif` twin alongside the `.webp`
     twin when a new `generate_avif` setting is on and the server has an AVIF
     delegate (`includes/engine/class-scout-optimize-imagick-engine.php`,
     `class-scout-optimize-presets.php`). AVIF params are added to all three
     presets (balanced avif q58); the twin is kept only when it beats the served
     primary, and servers without AVIF encode simply skip it. Threaded through
     the request/result value objects, the attachment processor (summary now
     rolls up `avif_bytes`), the upload pipeline, and the batch sweep.
  2. **Full-page delivery.** `Scout_Optimize_Delivery` now also buffers the
     rendered front-end page (`template_redirect`) and rewrites every `<img>`
     into a `<picture>`, not just the images that pass through
     `wp_content_img_tag`/`wp_get_attachment_image`. This covers page-builder
     (Elementor) widgets, galleries, and template markup that the two filters
     miss. The new `Scout_Optimize_Picture_Builder::rewrite_document()` holds the
     WordPress-light logic and skips anything already inside a `<picture>`, so it
     is safe to run alongside the filters. The `<picture>` now offers an AVIF
     `<source>` ahead of the WebP one so browsers pick the smallest format first.
  3. **Control panel.** The Tools → Scout Optimize page is now a real settings
     screen (capability-gated): preset picker, on/off toggles for
     optimize-uploads / WebP / AVIF / serve-next-gen, the dimension cap, and a
     live readout of whether this server's Imagick can create WebP and AVIF. This
     is what makes a site testable without editing options by hand
     (`includes/class-scout-optimize.php`).
  Bumped to 0.6.0.
- **Why:** The two highest-value wins after the Stage E measurement pass, plus
  the minimal settings surface needed to actually turn them on per site. On a
  sample of real Scout images, AVIF ran ~20% smaller than WebP (up to 27% on
  photographs), and a filter-only delivery path was leaving page-builder images
  with no twin served at all. Measurement also confirmed the byte war is carried
  by the dimension cap plus format, not by quality tuning; per-image perceptual
  quality is tracked as a separate future consistency feature, not a savings one.
- **Invariants held:** every original is still backed up before optimizing; the
  engine never mutates a source or grows a file (AVIF/WebP twins are kept only
  when smaller than the served primary); delivery is read-only markup and touches
  no files, never runs in admin/feed/REST, and no-ops while `rewrite_picture` is
  OFF. Activating this build with default settings still changes nothing.
- **Settings note:** a new `generate_avif` flag (default false) is seeded on
  activation; installs that predate it read it as false via `! empty()`, so no
  migration is needed.

## [0.5.0] — 2026-07-23 (Stage E: batch sweep)

- **What:** Optimize images already in the library, in the background.
  `Scout_Optimize_Batch` (`includes/batch/`) adds a capability-gated,
  confirmation-gated "Scout Optimize Sweep" admin page (Tools). A WP-Cron worker
  processes images in time-boxed batches and reschedules itself until done, so
  it never times out. Each image runs through the same processor as new uploads
  (original backed up, then optimized). Existing dimensions are left untouched
  (no resize), so live srcset/metadata are never altered. Idempotent (skips
  processed attachments) and resumable; a Stop button and plugin deactivation
  both unschedule cleanly. Bumped to 0.5.0.
- **Why:** Stage E of the build plan. New uploads were already handled (Stage C);
  this brings the existing library up to the same standard and generates the
  WebP twins that Stage D delivers.
- **Invariants held:** every original is backed up before optimizing (reversible
  per image); the engine never mutates a source or grows a file; the sweep runs
  only when an admin explicitly starts it.
- **Queue note:** implemented on WP-Cron self-rescheduling rather than the
  originally-planned Action Scheduler; adequate and dependency-free for a
  one-time library sweep. Resizing existing images (with a metadata update) is a
  possible follow-up.

## [0.4.0] — 2026-07-23 (Stage D: WebP delivery)

- **What:** Serve WebP twins to supporting browsers, gated OFF by default.
  `Scout_Optimize_Delivery` (`includes/delivery/`) filters front-end image
  output (`wp_content_img_tag`, `wp_get_attachment_image`) and, when
  `rewrite_picture` is on, wraps each `<img>` in a `<picture>` with a
  `type="image/webp"` source, keeping the original `<img>` as the fallback. A
  WebP source is offered only for files whose `.webp` twin exists on disk, so
  partial coverage is safe. `Scout_Optimize_Picture_Builder` holds the
  WordPress-light markup logic, unit-tested with stub resolvers. Bumped to 0.4.0.
- **Why:** Stage D of the build plan. The upload pipeline already generates the
  twins; this makes browsers actually download them (often another 25-80% off
  the delivered bytes for photos).
- **Invariants held:** the original `<img>` always remains as the fallback;
  delivery is read-only markup and modifies no files; admin, feed, and REST
  output are never altered; a no-op while `rewrite_picture` is OFF.

## [0.3.1] — 2026-07-23 (Stage C tuning: dimension cap + PNG quantization)

- **What:** More aggressive, good-practice defaults after the staging pilot.
  Full-size originals are capped at a configurable longest edge (default
  **1920px**) via the `big_image_size_threshold` filter, so WordPress scales
  them down with correct recorded metadata. The balanced preset now
  palette-quantizes PNGs, with dithering to soften banding; the engine's
  no-grow guard still discards any result that is not smaller. New
  `max_dimension` setting (seeded 1920; the pipeline falls back to 1920 for
  installs that predate it). Bumped to 0.3.1.
- **Why:** The pilot showed weak PNG-only savings on a photographic PNG (the
  expected Imagick PNG limitation). Capping dimensions and quantizing PNGs
  recovers real on-disk savings; next-gen delivery (Stage D) carries the rest.
  The pristine original is still backed up before any of this, so it is fully
  reversible.
- **Decisions ratified 2026-07-23:** keep PNG format (no PNG-to-JPEG
  conversion); dimension cap 1920px longest edge.

## [0.3.0] — 2026-07-23 (Stage C: upload pipeline)

- **What:** The new-upload optimization pipeline, gated OFF by default.
  `Scout_Optimize_Pipeline` (`includes/pipeline/`) hooks
  `wp_generate_attachment_metadata`; when `optimize_uploads` is on it backs up
  the original, then optimizes the served full-size and its generated sub-sizes
  in place via the engine, recording a per-attachment `_scout_optimize` summary.
  `Scout_Optimize_Attachment_Processor` holds the WordPress-light orchestration,
  unit-tested with a recording stub engine. Backup policy is originals-only: the
  full-size is preserved before optimizing and sub-sizes regenerate from it on
  restore. Bumped to 0.3.0.
- **Why:** Stage C of the build plan. The first stage that changes site
  behaviour, but only once Scout flips the flag per site (staging first). Enable
  runbook: `docs/enable-optimization-on-staging.md`.
- **Invariants held:** the original is backed up before any optimization; the
  engine still never mutates a source or grows a file; the pipeline is
  idempotent (skips already-processed attachments) and a no-op while the flag is
  OFF.

## [0.2.0] — 2026-07-23 (Stage B: engine)

- **What:** The optimization engine, loaded but wired to no hook. The
  `Scout_Optimize_Engine` interface with an on-server Imagick implementation,
  immutable `Request`/`Result` value objects, the three ratified quality presets
  with per-format encoder parameters, and a null-engine fallback with a selecting
  factory (`includes/engine/`); plus the `Scout_Optimize_Originals` backup and
  restore primitives (`includes/originals/`). PHPUnit coverage for presets, value
  objects, the originals store, and the Imagick engine (source immutability,
  no-grow, WebP twin, downscale, too-large, animated-GIF skip). CI now installs
  Imagick and runs the suite. Bumped to 0.2.0.
- **Why:** Stage B of the build plan, unblocked by the WP Engine staging audit
  (Imagick 7 and WebP encode confirmed). The seven Stage B decisions are ratified
  and folded into `docs/stage-b-engine-spec.md`.
- **Invariants held:** the engine never modifies its source, never throws, and
  never grows a file; originals preservation and per-image restore now have a
  home (`Scout_Optimize_Originals`). All feature flags remain OFF and no hook
  invokes the engine, so activating this build still changes nothing on a site.

## [0.1.0] — 2026-07-22 (Stage A: scaffold)

- **What:** Initial plugin skeleton. Plugin header and constants
  (`scout-optimize.php`), singleton bootstrap with idempotent activation,
  capability gate (`manage_scout_optimize`), placeholder Tools page
  (`includes/class-scout-optimize.php`), non-destructive `uninstall.php`,
  read-only WP Engine environment audit script
  (`tools/audit-environment.php`), tooling config (composer, PHPCS/WPCS,
  wp-env, GitHub Actions CI).
- **Why:** Stage A of the approved build plan (`scout-optimize-build-plan.md`
  in the Scout project): a skeleton that activates cleanly and does nothing,
  plus the audit that gates Stage B on verified WP Engine facts.
- **Invariants established:** no destructive code paths anywhere; originals
  preservation is a lifecycle-level guarantee (see `uninstall.php` header);
  all features default OFF in seeded settings.
