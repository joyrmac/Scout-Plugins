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

## Page fieldsets: every headline and picture in its own field (1.1.0+)

A designed page has a fixed layout and a set of words and pictures inside it.
Fieldsets give the editor those words and pictures as labelled fields, grouped
the way the page reads, and hide the block editor so the layout stays put.

```php
add_action( 'scout_core_register', function () {
    scout_core_register_fields( 'about', array(
        'label'    => 'About page content',
        'for'      => array( 'template' => 'templates/about.php' ),
        'sections' => array(
            'hero' => array(
                'label'  => 'Top of page',
                'fields' => array(
                    'hero_heading' => array( 'label' => 'Headline' ),
                    'hero_text'    => array( 'label' => 'Intro paragraph', 'control' => 'textarea' ),
                    'hero_image'   => array( 'label' => 'Background photo', 'control' => 'image' ),
                ),
            ),
        ),
    ) );
} );
```

`for` takes `front_page => true`, `template => 'templates/x.php'`, `slug =>
'about'`, or `post_type => 'service'` (every post of that type). The theme
reads a value with `scout_core_field( 'hero_heading' )` and prints an image with
`scout_core_image( 'hero_image', 'full', array( 'class' => 'hero__img' ) )`.

Site-wide content that belongs to no page (header, footer, a shared call-to-
action band) goes in a settings group, which appears as its own tab under the
Scout menu:

```php
scout_core_register_settings_group( 'site', array(
    'label'    => 'Site content',
    'sections' => array( /* same shape as a fieldset */ ),
) );
// read: scout_core_setting( 'site', 'footer_tagline' ), scout_core_image( 'site:logo' )
```

Keep fieldsets to what actually changes. A page of forty fields is still
better than a page builder, but a paragraph nobody will ever edit can stay in
the template.

## Editable pages: words and photos on the page, layout locked (1.2.0+)

The default for designed pages. The client opens a page in the block editor
and sees it as it looks on the site; they change words and photos, and nobody
can move, delete or restyle a section. Icons and other wordless markup stay
in the theme. Page fieldsets (above) stay for site-wide content.

**Opt in from the theme:**

```php
add_theme_support( 'scout-editable-pages', array(
    'content_dir' => get_template_directory() . '/content',   // index.php + <page>.php
    'editor_css'  => array( get_template_directory_uri() . '/assets/css/editor.css' ),
) );
```

In each page template, where the body goes:

```php
<?php if ( ! scout_core_render_editable() ) : ?>
    ... the approved markup (shown until the content is imported) ...
<?php endif; ?>
```

Theme parts (a form, a widget) render through a filter:

```php
add_filter( 'scout_core_editable_part', function ( $html, $name ) {
    return 'contact-form' === $name ? my_theme_contact_form() : $html;
}, 10, 2 );
```

**Build the content** with `tools/editable-pages/build_blocks.py` in this repo:
`page` turns an approved page body into `content/<key>.php` (and refuses one
that does not render back to the approved page); `editor-css` scopes the site
stylesheets to the editor canvas. Mark theme parts in the body as
`<scout-part name="contact-form" label="Contact form"></scout-part>`.

**What it does:**

- Five blocks: `scout/box` (layout, locked), `scout/text` (words, editable),
  `scout/image` (photos, replaceable, keeps the approved image's class and
  loading behavior), `scout/frozen` (icons and marks, kept in the theme) and
  `scout/part` (theme parts).
- The lock: the page sits in one root block with WordPress's content-only
  lock. Administrators can rearrange with WordPress's "Edit pattern" button;
  everyone else gets the lock on the whole editor and no unlock button.
- A "Page photos" panel in the page sidebar lists every photo, background
  photos included, with Replace and Use original.
- Import: theme activation and every new theme or Scout Core version fill
  empty pages and refresh pages nobody has edited (`_scout_editable_hash`).
  An edited page is never overwritten. `scout_core_import_editable()` runs it
  on demand.
- FAQ schema: on an editable page, the Scout schema graph's FAQPage comes
  from the visible questions (`<details><summary>`), so edits never leave the
  search markup behind. `scout_core_editable_faq()` returns the entries for
  themes that print their own schema.
- Review notes (`<span class="confirm">`) print for people who can edit pages
  only; set `'review_class'` to use another class.
- Content files are PHP, so opening one in a browser prints nothing.

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

Field `control` values: `text`, `textarea`, `richtext`, `number`, `url`,
`select` (with an `options` array), `checkbox`, and `image` (a media-library
picker that stores the attachment ID). `scout-core` registers the post type, the REST-exposed meta,
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

## APIs other plugins depend on

These are a contract. Renaming or removing one breaks the plugin that uses it on
every client site at the next automatic update. CI checks them
(`tools/core-contract.sh`); change them only together with the dependent plugin.

| API | Used by | For |
|---|---|---|
| `Scout_Core_Business::get()` (keys `name`, `phone`, `email`, `city`, `region`, `postal`, `same_as`) | scout-cards | Business details on the cards and in the saved contact |
| The `scout` admin menu slug | scout-cards | Puts Cards & Links inside the Scout menu |
| `Scout_SEO_Head::output` on `wp_head` priority 1 | scout-cards | Removed on card pages, which print their own tags |

## Versioning ritual

Every change to this plugin:

1. Bumps the `Version:` header in `scout-core.php`.
2. Bumps `SCOUT_CORE_VERSION`.
3. Adds a dated entry to `CHANGELOG.md`.
