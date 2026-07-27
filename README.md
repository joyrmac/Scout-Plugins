# Scout Plugins

The in-house WordPress plugin suite that powers Scout client websites. Content
model, schema, SEO, and spam protection, all built and maintained by Scout Media
& Consulting.

This repo is the source of truth for the plugin code. Each plugin is a normal
WordPress plugin folder: drop it in `wp-content/plugins/`, activate it, done.

---

## The one rule

**Functionality in plugins, presentation in the theme.** If a post type or a
field lived in a theme's `functions.php`, switching themes would orphan the
content. Keeping the model in plugins is what makes a redesign a weekend job
instead of a data-loss event.

---

## What's in here

| Plugin | Version | What it does | Requires |
|---|---|---|---|
| [`scout-core`](scout-core/) | 0.1.0 | The content model: custom post types, native fields, the business identity, and Block Bindings. Everything else reads from it. | WP 6.5+, PHP 8.0+ |
| [`scout-schema`](scout-schema/) | 0.1.0 | One JSON-LD `@graph` (LocalBusiness, WebSite, FAQPage, Review/AggregateRating, BlogPosting) built from Scout Core data. | `scout-core` |
| [`scout-seo`](scout-seo/) | 0.1.0 | Titles, meta descriptions, canonicals, Open Graph, Twitter tags, robots control, an editor snippet preview, and XML sitemap tuning. The Yoast replacement. | WP 6.5+, PHP 8.0+ |
| [`scout-forms-guard`](scout-forms-guard/) | 0.1.0 | Spam protection for Gravity Forms: honeypot, time trap, and a signed token. No reCAPTCHA, no Akismet, no third-party calls. | Gravity Forms |
| [`scout-rvpark`](scout-rvpark/) | 0.1.0 | The worked example of the companion pattern: one client's specific content types, registered without forking Scout Core. | `scout-core` |

Every plugin carries its own semver, its own `CHANGELOG.md`, and its own README.
A fix in any of them ships to every client through a normal plugin update.

---

## How they fit together

`scout-core` is the foundation. It owns the data, and the other plugins read
from it rather than storing their own copies.

```
                    ┌─────────────────┐
                    │   scout-core    │  post types, fields,
                    │  (the data)     │  business identity, bindings
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
     ┌────────▼──────┐ ┌─────▼───────┐ ┌────▼──────────┐
     │ scout-schema  │ │  scout-seo  │ │ scout-{client}│
     │   JSON-LD     │ │  head tags  │ │  their types  │
     └───────────────┘ └─────────────┘ └───────────────┘

     ┌────────────────────┐
     │ scout-forms-guard  │  independent, hooks Gravity Forms
     └────────────────────┘
```

`scout-schema` and `scout-seo` split the job cleanly: schema owns the JSON-LD,
SEO owns the `<head>` tags. They do not overlap.

---

## Install order

Activate in this order so dependencies resolve cleanly:

1. `scout-core`
2. `scout-schema`
3. `scout-seo`
4. `scout-forms-guard`
5. The client companion plugin (`scout-rvpark` is the reference copy)

Then go to **Settings → Scout Business** and fill in the name, address, phone,
and email. That is the one place the business facts live, and every other plugin
reads from it.

Full walkthrough, including how to set up a free local test site and verify the
schema in Google's validator, is in [`INSTALL.md`](INSTALL.md).

---

## Adding a client's own types

Never fork `scout-core`. Each client gets a small companion plugin that
registers their specific types through the public API:

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

`scout-core` registers the post type, the REST-exposed meta, the editor meta
box, and the block bindings for you. See [`scout-rvpark`](scout-rvpark/) for the
full worked example.

---

## Versioning ritual

Every change to a plugin:

1. Bumps the `Version:` header in the main plugin file.
2. Bumps the matching `SCOUT_*_VERSION` constant.
3. Adds a dated entry to that plugin's `CHANGELOG.md`.

---

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

---

Built by [Scout Media & Consulting](https://scoutraleigh.com) in Fuquay-Varina, NC.
