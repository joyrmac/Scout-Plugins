# Scout SEO — Changelog

## 0.1.0 — 2026-06-18

Initial scaffold.

- Per-page `<title>` via `pre_get_document_title`.
- Meta description (custom, or auto from excerpt/content).
- Canonical URL for singular, home, archives, and taxonomies, with a per-page
  override; core's singular-only `rel_canonical` removed to avoid duplicates.
- Open Graph and Twitter card tags, including article published/modified times
  and a featured-image (or site-icon) fallback.
- Robots control via the core `wp_robots` filter: noindex on search and 404, and
  a per-page noindex toggle.
- "Scout SEO" editor box: SEO title, meta description, canonical, noindex, with a
  live snippet preview and character counts (vanilla JS, no enqueue).
- Core sitemap tuning: noindexed posts excluded from `/wp-sitemap.xml` (core's
  sitemap is reused, not rebuilt).
