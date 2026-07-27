# Scout Core

The content model for every Scout website: custom post types, native fields,
the business identity, and the Block Bindings that let the theme display all of
it. It is a **plugin on purpose**. Content and SEO live here, design lives in
the theme, so you can redesign a client's site without losing their content.

> This README is also the build order for the whole in-house platform. The
> suite overview and how the plugins connect are in the
> [repo README](../README.md).

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
3. Go to **Settings → Scout Business** and fill in the NAP, hours, and profiles.
4. (Per client) add a companion plugin for that client's specific types. See
   [`../scout-rvpark`](../scout-rvpark) for the worked example.

Requires WordPress 6.5+ (for Block Bindings) and PHP 8.0+.

---

## What ships in the box

**Universal post types** (confirmed across the Maravela's and North Crest builds):

| Type | Title | Editor | Fields |
|---|---|---|---|
| `testimonial` | Author name | The quote | location, rating, source, source URL, date, + featured image for handwritten scans |
| `faq` | The question | The answer | none (title + content is all FAQPage needs) |

**Business identity** (`Settings → Scout Business`): name, legal name, schema
type, phone, email, full address, price range, geo, hours, and profile URLs.
One option, read everywhere.

---

## Adding a client's own types (the companion pattern)

Never fork `scout-core`. Each client gets a tiny companion plugin that registers
their specific types through the public API, on the `scout_core_register` action:

```php
add_action( 'scout_core_register', function () {
    scout_core_register_type( 'amenity', array(
        'singular' => 'Amenity',
        'plural'   => 'Amenities',
        'fields'   => array(
            'summary' => array( 'label' => 'Summary', 'control' => 'textarea' ),
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

Design lives in the theme; this is the seam where content flows into it. Bind a
block's content to a field on the current post, or to the business identity:

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

The editor types the data into a field; the design never changes unless the
theme changes. That is the content/design separation, enforced.

---

## The platform build order (first to last)

`scout-core` is step one because everything else reads from it. Build in this
order:

1. **`scout-core`** — content model + business identity + bindings. ✅ this plugin.
2. **`scout-base`** (theme) — block theme, `theme.json` design tokens, templates
   and patterns that bind to scout-core fields. The static HTML from Phase 5
   converts into this.
3. **`scout-schema`** — JSON-LD graph (LocalBusiness/subtype, FAQPage,
   Review/AggregateRating, BlogPosting), reading the business identity and the
   testimonial/faq content. Stable `@id`s.
4. **`scout-seo`** — titles, meta descriptions, canonicals, OG/Twitter tags, XML
   sitemap, robots, and the editor snippet-preview meta box. The Yoast replacement.
5. **`scout-forms-guard`** — honeypot + timestamp + signed-token spam protection,
   hooked into Gravity Forms (the one third-party plugin we keep).
6. **`scout-importer`** (optional) — WP-CLI import/sync of structured content for
   builds and migrations (the self-healing provisioning pattern, kept out of the
   theme so content survives a redesign).

Each gets its own folder in this repo, its own semver, and its own
`CHANGELOG.md`. A fix in any of them ships to every client through a normal
plugin update.

---

## Versioning ritual

Every change to this plugin:

1. Bumps the `Version:` header in `scout-core.php`.
2. Bumps `SCOUT_CORE_VERSION`.
3. Adds a dated entry to `CHANGELOG.md`.
