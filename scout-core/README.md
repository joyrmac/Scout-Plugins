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

**Spam protection (1.6.0+).** Both on by default, switched under **Scout →
Spam protection**. The email guard codes every email address on the finished
page (one output buffer, so theme, bindings, plugins and schema are all
covered); visitors see and click it as usual, and the JSON-LD keeps the same
value with `@` written as `\u0040`. Closing comments makes `comments_open` and
`pings_open` false outside wp-admin, drops the `X-Pingback` header and the
pingback XML-RPC methods, and deletes nothing. Filters:
`scout_core_email_guard`, `scout_core_close_comments`. Test:
`php tools/tests/email-guard.php`.

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

## Site Sync: theme copy in, hand edits kept (1.4.0+)

A theme lists the copy it bundles in `scout-sync.json` at its root: page
content, page fields, SEO, shared settings, site title and tagline. Scout
compares each item with what WordPress holds and with what was there at the
last sync. Pages nobody edited update on their own after a theme update;
anything changed in WordPress waits on **Scout → Content → Site Sync** for
"Keep the WordPress copy" or "Use the theme copy". A page the bundle names
and the site lacks is created when its entry says `"create": true` (pages
publish, `posts` arrive as drafts, 1.6.5). Every sync can be undone. A
theme without the file gets the screen in status-only mode.

Manifest format, states and write safety: `docs/site-sync-spec.md`
("As built"). Command line: `wp scout sync status`, `wp scout sync run
--dry-run`. Test: `tools/tests/site-sync.php` against a disposable WordPress.

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

## The site's design: `scout_design()` (1.6.3+)

The theme's `DESIGN.md` is the one design source for the site, plugins
included (Scout Site Standard §4). Scout Core reads its front matter and hands
every plugin the same tokens, so no plugin carries a color picker for what the
theme already says:

```php
$d = scout_design();                       // mode, colors, fonts, radius, logo, mark (+ logo_url, mark_url)
$d = scout_design( 'call buttons' );       // the "## Feature: call buttons" section laid over the tokens
echo '<style>' . scout_design_css() . '</style>';   // :root{--scout-bg:...;--scout-accent-fill:...;--scout-radius-card:...}
scout_design_issues();                     // contrast pairs that fail 4.5:1 (Scout Build shows them in Site health)
```

Order of truth: Scout's defaults, then the active theme's `theme.json`
palette and fonts, then the parent theme's `DESIGN.md`, then the child's. A
theme without DESIGN.md still gets sensible tokens.

`scout_core_owns( 'hours' )` tells a theme whether a plugin does a job on this
site, for the few fallbacks the standard allows (rule 6): the theme's own hours
line runs only while `! scout_core_owns( 'hours' )`.

## Breadcrumbs, verification, thank-you pages, hardening and cache purge (1.8.0+)

- **Breadcrumbs:** a theme declares `add_theme_support( 'scout-breadcrumbs' )` and calls
  `scout_core_breadcrumbs()` where the links go. Core then adds the matching
  `BreadcrumbList` schema. Remove the theme's own breadcrumb schema in the same release.
  Adjust the trail with `scout_core_breadcrumb_trail`, for example to put a page that acts
  as an archive (Work, Services) before its items.
- **Verification:** Get found → SEO, "Google Search Console verification code" and
  "Bing Webmaster Tools verification code". The tags print on the front page only.
- **Thank-you pages:** `noindex` and out of the sitemap. These are Gravity Forms
  confirmation pages, plus the addresses thank-you and thanks. Add more with
  `scout_core_thanks_pages`.
- **Hardening:** switches under spam protection (`hide_users`, `xmlrpc_off`,
  `browser_headers`). Filters: `scout_core_hide_users`, `scout_core_xmlrpc_needed`,
  `scout_core_browser_headers`. Themes and companions stop doing this themselves.
- **Staging guard:** on a staging copy, email goes only to Scout and calls to newsletter,
  texting and payment services are refused (`scout_core_staging_mail_to`,
  `scout_core_staging_blocked_hosts`, `SCOUT_STAGING`).
- **Cache purge:** after the active theme or a Scout plugin updates, WP Engine and WP
  Rocket caches purge by themselves (`scout_core_purge_after_update`). Cloudflare is
  still purged by hand.

## APIs other plugins depend on

These are a contract. Renaming or removing one breaks the plugin that uses it on
every client site at the next automatic update. CI checks them
(`tools/core-contract.sh`); change them only together with the dependent plugin.

| API | Used by | For |
|---|---|---|
| `Scout_Core_Business::get()` (keys `name`, `phone`, `email`, `city`, `region`, `postal`, `same_as`) | scout-cards | Business details on the cards and in the saved contact |
| The `scout` admin menu slug | scout-cards | Puts Cards & Links inside the Scout menu |
| `Scout_SEO_Head::output` on `wp_head` priority 1 | scout-cards | Removed on card pages, which print their own tags |
| `scout_design()`, `scout_design_css()`, `scout_design_issues()` | scout-build and every plugin with a front-end look | The site's colors, fonts, corners, logo and mark from the theme's DESIGN.md |
| `scout_core_owns( $job )` | every Scout theme (via the theme kit) | Whether a plugin does a job here, so a theme fallback stands down |

## Versioning ritual

Every change to this plugin:

1. Bumps the `Version:` header in `scout-core.php`.
2. Bumps `SCOUT_CORE_VERSION`.
3. Adds a dated entry to `CHANGELOG.md`.

<!-- visitor-data:start -->
## Visitor data

| Cookie | Why | How long |
|---|---|---|
| `scoutSections:<screen name> (browser storage)` | Remembers which sections of an edit screen a signed-in editor left open. It is used only inside the site's dashboard, never on public pages, and holds no personal information. | Until the browser's saved site data is cleared |

Scout Core keeps no information about visitors. Its email guard only changes how email addresses are written into the page, and it stores nothing.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
