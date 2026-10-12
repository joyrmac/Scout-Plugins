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
| [`scout-core`](scout-core/) | 1.1.3 | The engine: business identity, content model (post types, fields, Block Bindings), the JSON-LD schema graph, and the in-house SEO head tags and sitemap. One plugin, one Scout dashboard. | WP 6.5+, PHP 8.0+ |
| [`scout-forms-guard`](scout-forms-guard/) | 0.1.2 | Spam protection for Gravity Forms: honeypot, time trap, and a signed token. No reCAPTCHA, no Akismet, no third-party calls. | Gravity Forms |
| [`scout-cards`](scout-cards/) | 1.0.5 | Digital business cards and a link-in-bio page on the client's own site: Save contact, share mode with a QR code, home-screen icon, `/links/`. Reads the business identity from Scout Core. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-reviews`](scout-reviews/) | 0.4.2 | Reviews from Google, Facebook, Yelp, Clutch, and the rest, shown in a custom design. Google reviews sync daily into an inbox; one click adds each to the right pages. A `/review/` page, private note form, QR code, printable cards and ready-to-send messages for asking clients. | WP 6.5+, PHP 8.0+ |
| [`scout-analytics`](scout-analytics/) | 0.1.0 | Google Tag Manager (our standard on every client site) with Google consent mode set first, a small opt-out cookie notice, and one click-reporting API for cards, links and booking. | WP 6.5+, PHP 8.0+ |
| [`scout-chat`](scout-chat/) | 0.1.0 | A chat assistant in the corner of the site: answers common questions, books calls, takes change requests, and saves every other question with the visitor's email to an inbox. AI answers once connected to an AI server (key stays off the site). | WP 6.5+, PHP 8.0+ |
| [`scout-aeo`](scout-aeo/) | 0.1.3 | AI search readiness: names the AI search crawlers in robots.txt and lets them in (live site only), serves a plain-text guide at `/llms.txt`, and adds a "Questions this page answers" box to every page with matching FAQ schema. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-build`](scout-build/) | 0.2.5 | The Site Build hub: one screen to see and switch the site's Scout features (operators only), go back a version, request a change, and check site health, including a "Ready for AI search" scorecard. Reads the feature list from `catalog.json`. | WP 6.5+, PHP 8.0+ |
| [`scout-rvpark`](scout-rvpark/) | 0.1.2 | The worked example of the companion pattern: one client's specific content types, registered without forking Scout Core. | `scout-core` |

> `scout-schema` and `scout-seo` shipped separately until Scout Core 1.0.0 folded
> them in as `includes/schema/` and `includes/seo/`. They are retired as
> standalone plugins.

Every plugin carries its own semver, its own `CHANGELOG.md`, and its own README.
A fix in any of them ships to every client through a normal plugin update.

---

## How they fit together

`scout-core` is the foundation. It owns the data, and the other plugins read
from it rather than storing their own copies.

```
          ┌──────────────────────────────────┐
          │            scout-core            │
          │  business identity · content     │
          │  model · schema · SEO · admin    │
          └────────────────┬─────────────────┘
                           │ scout_core_register
                  ┌────────▼─────────┐
                  │  scout-{client}  │  their own types
                  └──────────────────┘

     ┌────────────────────┐   ┌────────────────┐   ┌───────────────┐
     │ scout-forms-guard  │   │ scout-optimize │   │ scout-reviews │  all independent
     └────────────────────┘   └────────────────┘   └───────────────┘
```

Everything reads from the one business identity, so a client's name, address,
and phone are typed in once and appear in the schema, the head tags, and the
page copy without drifting apart.

---

## Install order

Activate in this order so dependencies resolve cleanly:

1. `scout-core`
2. `scout-forms-guard`
3. The client companion plugin (`scout-rvpark` is the reference copy)

Then open the Scout admin screen and fill in the name, address, phone, and
email. That is the one place the business facts live, and every other plugin
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

## Updates

Client sites update themselves from this repo's releases. Every plugin carries
an `Update URI:` header, so WordPress hands its update check to the plugin,
which looks up the newest release here. Updates then appear on the client's
Plugins screen like any other, and WordPress's own auto-update toggle works on
them. No updater plugin is installed on the client site and no third-party
service sits in the middle.

Shipping a fix to every site: bump the plugin's `Version:` header, add a
changelog entry, and merge the pull request into `main`. The Release workflow
then releases every plugin whose version has no release yet (Scout Core
first): it tags it, builds the zip, and publishes the release. A plugin whose
version did not change is skipped. **Actions → Release → Run workflow** still
works as a manual backup. Full process, including what breaks it, is in
[`RELEASING.md`](RELEASING.md).

This repo has to stay public for that to work. The updater calls the GitHub API
without credentials, which is what keeps client sites free of access tokens.

---

## Versioning ritual

Every change to a plugin:

1. Bumps the `Version:` header in the main plugin file.
2. Bumps the matching `SCOUT_*_VERSION` constant.
3. Adds a dated entry to that plugin's `CHANGELOG.md`.

The release build checks the tag against the `Version:` header and fails if they
disagree, so a forgotten bump cannot ship.

---

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

---

Built by [Scout Media & Consulting](https://scoutraleigh.com) in Fuquay-Varina, NC.
