# Scout SEO

The in-house replacement for Yoast. It controls everything search engines read
in the page `<head>`, gives editors a familiar snippet preview, and tunes
WordPress's built-in sitemap. **Scout Schema** handles the JSON-LD; this plugin
handles the meta tags, so the two never overlap.

Works on its own. Best paired with Scout Core (business identity) and Scout
Schema. WordPress 6.5+, PHP 8.0+.

---

## What it does

- **Title tag** per page (custom, or the page title by default).
- **Meta description** (custom, or auto-generated from the excerpt/content).
- **Canonical URL** for every context, with a per-page override.
- **Open Graph + Twitter cards** so links shared to Facebook, iMessage,
  LinkedIn, and X show a proper title, description, and image.
- **Robots control**: search results and 404s are set to noindex, and any page
  can be hidden from search with one checkbox.
- **The "Scout SEO" editor box**: SEO title, meta description, canonical, and a
  noindex toggle, with a live Google-style preview and character counts.

---

## What it deliberately does NOT do

It does **not** rebuild the XML sitemap. WordPress core already publishes one at
`/wp-sitemap.xml` and lists it in `robots.txt`. Rebuilding that would be effort
spent re-creating something the platform gives us for free. Instead Scout SEO
just keeps any noindexed page out of the core sitemap, so the sitemap and the
robots tags always agree.

This is the governing rule in practice: own the parts that differentiate us
(clean head tags, real control), reuse the parts core already does well.

---

## Filters

- `scout_seo_post_types` — which post types get the SEO box (default: all public
  types except attachments).

---

## How the head splits between the two plugins

| In the `<head>` | Owned by |
|---|---|
| `<title>`, meta description, canonical, OG, Twitter, robots | **scout-seo** |
| JSON-LD `@graph` (LocalBusiness, Review, FAQPage, BlogPosting) | **scout-schema** |

Keeping that line clean is why there are two plugins instead of one.
