# Scout Core

The engine for a Scout client site: the business identity, the content model
(post types, native fields, Block Bindings), the JSON-LD schema graph, and the
in-house SEO head tags and sitemap tuning. One plugin, one Scout dashboard.

It is a **plugin on purpose**. Content and SEO live here, design lives in the
theme, so a client's site can be redesigned without losing either.

> **1.0.0 folded in two plugins.** `scout-schema` and `scout-seo` used to ship
> separately and are now `includes/schema/` and `includes/seo/`. They are
> retired as standalone plugins. Everything a site needs is here.

---

## The one rule

**Functionality in plugins, presentation in the theme.** If a post type or a
field lived in the theme's `functions.php`, switching themes would orphan the
content. Keeping the model in `scout-core` is what makes a rebuild a weekend job
instead of a data-loss event.

---

## Install

1. Copy `scout-core/` into `wp-content/plugins/`.
2. Activate it. On activation it registers the types and flushes rewrite rules.
3. Fill in the business identity from the Scout admin screen: name, address,
   phone, email, hours, profiles.
4. (Per client) add a companion plugin for that client's own types. See
   [`../scout-rvpark`](../scout-rvpark) for the worked example.

Requires WordPress 6.5+ (for Block Bindings) and PHP 8.0+.

---

## What ships in the box

**Universal post types**

| Type | Title | Editor | Fields |
|---|---|---|---|
| `testimonial` | Author name | The quote | location, rating, source, source URL, date, plus a featured image for handwritten scans |
| `faq` | The question | The answer | none (title + content is all FAQPage needs) |

**Business identity.** Name, legal name, schema type, phone, email, full
address, price range, geo, hours, and profile URLs. One option, read everywhere,
typed in once.

**Schema.** One JSON-LD `@graph` in `wp_head`: LocalBusiness (or the configured
subtype), WebSite, FAQPage, Review and AggregateRating from testimonials, and
BlogPosting on posts. Stable `@id`s throughout.

**SEO.** Titles, meta descriptions, canonicals, Open Graph and Twitter tags,
robots control, an editor snippet preview with live character counts, global
defaults, and tuning of WordPress core's own XML sitemap. The Yoast replacement.

---

## Adding a client's own types (the companion pattern)

Never fork `scout-core`. Each client gets a small companion plugin that
registers their types through the public API, on the `scout_core_register`
action:

```php
add_action( 'scout_core_register', function () {
    scout_core_register_type( 'attorney', array(
        'singular' => 'Attorney',
        'plural'   => 'Attorneys',
        'fields'   => array(
            'bar_number' => array( 'label' => 'Bar Number', 'control' => 'text' ),
        ),
    ) );
} );
```

Field `control` values: `text`, `textarea`, `number`, `url`, `select` (with an
`options` array). `scout-core` registers the post type, the REST-exposed meta,
the editor meta box, and the block bindings for you. Field key `summary` is
stored as meta key `scout_summary`.

---

## Displaying it in the theme (Block Bindings)

Design lives in the theme; this is the seam where content flows into it.

```html
<!-- A field on the current post -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":
     {"source":"scout/field","args":{"key":"summary"}}}}} -->
<p></p>
<!-- /wp:paragraph -->

<!-- A value from the business identity (works on any page) -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":
     {"source":"scout/business","args":{"key":"phone"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

The editor types data into a field; the design never changes unless the theme
changes. That is the content/design separation, enforced.

---

## Updates

The plugin updates itself from the Scout Plugins releases through the
`Update URI:` header, so a fix ships to every client site through the normal
Plugins screen. See [`../RELEASING.md`](../RELEASING.md).

---

## Versioning ritual

Every change to this plugin:

1. Bumps the `Version:` header in `scout-core.php`.
2. Bumps `SCOUT_CORE_VERSION`.
3. Adds a dated entry to `CHANGELOG.md`.
