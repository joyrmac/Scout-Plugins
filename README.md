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

| Plugin | Version | Kind | What it does | Requires |
|---|---|---|---|---|
| [`scout-core`](scout-core/) | 1.10.0 | Core | The engine: business facts typed once, the content model (post types, fields, Block Bindings), the JSON-LD schema graph, the in-house SEO head tags and sitemap, Page copy (Site Sync) and the Change log. | WP 6.5+, PHP 8.0+ |
| [`scout-build`](scout-build/) | 0.12.4 | Core | The Scout dashboard hub: Home, To do, Site status, Features and plan, Updates with the update order, Site history, Request a change, theme self-updates from the client's own repo. | WP 6.5+, PHP 8.0+ |
| [`scout-aeo`](scout-aeo/) | 0.2.10 | Core | AI search readiness: names the AI crawlers in robots.txt, serves `/llms.txt`, sends IndexNow pings, and adds a questions-and-answers box with matching FAQ schema to every page. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-optimize`](scout-optimize/) | 0.9.9 | Core | Image optimizer: every upload is resized and compressed, WebP and AVIF twins are served, and the original is kept as a backup. | WP 6.5+, PHP 8.1+ |
| [`scout-forms-guard`](scout-forms-guard/) | 0.3.6 | Core | Spam protection for Gravity Forms: honeypot, time trap and a signed token. Refuses files sent to hidden File Upload fields. No reCAPTCHA, no third-party calls. | Gravity Forms |
| [`scout-analytics`](scout-analytics/) | 0.1.21 | Core | Google Tag Manager with consent mode set first, a small opt-out cookie notice, and one click-reporting API every Scout feature uses. | WP 6.5+, PHP 8.0+ |
| [`scout-contact`](scout-contact/) | 0.1.17 | Core | Call buttons: a Call, Text and Let's talk bar pinned to the bottom of the screen on phones. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-redirects`](scout-redirects/) | 0.1.16 | Core | Moved pages: forward old addresses, log the missing pages people hit, fix a broken link in one click, auto-forward when a page's address changes, CSV import and export. | WP 6.5+, PHP 8.0+ |
| [`scout-hours`](scout-hours/) | 0.1.14 | Core | Weekly hours and holiday closures in one place: an Open now line, hours in the schema, and a reminder to update the Google Business Profile before each holiday. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-accessibility`](scout-accessibility/) | 0.1.18 | Core | An honest accessibility statement page, a footer link, weekly checks for what a computer can find, and a launch checklist for what needs a person. No overlays, ever. | WP 6.5+, PHP 8.0+ |
| [`scout-team`](scout-team/) | 0.3.0 | Core | People: one profile per person that feeds the bio page, the team page, bylines, business cards, speaker pages and Person schema. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-law`](scout-law/) | 0.4.3 | Core, law firms | Law firm tools: disclaimer lines set once and shown where they belong, approval before publishing kept as the firm's advertising record, Reviewed by stamps on a review schedule, form entries that delete themselves. | WP 6.5+, PHP 8.0+ (Gravity Forms, Scout Case Results, Scout Reviews optional) |
| [`scout-results`](scout-results/) | 0.1.15 | Bonus, law firms | Case results, each approved by the firm, with the firm's disclaimer next to every result and practice-area filters. | WP 6.5+, PHP 8.0+ |
| [`scout-reviews`](scout-reviews/) | 0.5.12 | Plan feature | Reviews from Google, Facebook, Yelp and the rest in one place, a daily Google sync into an inbox, a `/review/` page with QR code and printable cards for asking clients. Honest totals, no review schema. | WP 6.5+, PHP 8.0+ |
| [`scout-cards`](scout-cards/) | 1.0.27 | Bonus | Digital business cards and a link-in-bio page on the client's own site: Save contact, share mode with a QR code, home-screen icon, `/links/`. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-chat`](scout-chat/) | 0.3.5 | Bonus | A chat assistant in the corner of the site: answers common questions, books calls, takes change requests, saves everything else to an inbox. AI answers once connected to an AI server. | WP 6.5+, PHP 8.0+ |
| [`scout-newsletter`](scout-newsletter/) | 0.1.7 | Plan feature | Sends people who tick the newsletter box to the client's Beehiiv list, tagged by where they joined, with every agreement saved. | WP 6.5+, PHP 8.0+ (WooCommerce, Scout Events, Scout Members, Gravity Forms optional) |
| [`scout-events`](scout-events/) | 0.16.7 | Plus | Events, sessions and speakers edited in plain forms, with schedules, directories, tickets from a WooCommerce shop, Event schema and a spreadsheet import. | WP 6.5+, PHP 8.0+ (Scout Core optional) |
| [`scout-members`](scout-members/) | 0.4.7 | Plus | A membership run from the dashboard: applications, approval, sign-in, members-only pages and events, plans and renewals, invitations, CSV import. | WP 6.5+, PHP 8.0+ (Scout Events optional) |
| [`scout-booking`](scout-booking/) | 0.2.1 | Plus | Private sessions booked on the site: session types, open times from the working hours, requests the owner approves or deposits through WooCommerce, reminders and a calendar feed. | WP 6.5+, PHP 8.0+ (WooCommerce, Scout Hours optional) |
| [`scout-partners`](scout-partners/) | 0.1.8 | Plus | Partners and sponsors by level, each with a logo, link and the places they appear, with monthly click counts. | WP 6.5+, PHP 8.0+ (Scout Events optional) |
| [`scout-crm`](scout-crm/) | 0.1.5 | Plus | One customer list in FluentCRM: ticket buyers, sign-ups, volunteers, vendors, presenters and members, tagged by what they did. | WP 6.5+, PHP 8.0+, FluentCRM (Scout Events, Scout Members) |
| [`scout-writing`](scout-writing/) | 0.1.6 | Plus | A private writing room in the dashboard: prompts in steps, answers that save as they type, voice memos, Send to Scout. | WP 6.5+, PHP 8.0+ |
| [`scout-sitecheck`](scout-sitecheck/) | 0.2.11 | Site-specific | The free website check on scoutraleigh.com: eight checks, results on the page and by email, every request in an inbox. | WP 6.5+, PHP 8.0+ |
| [`scout-rvpark`](scout-rvpark/) | 0.2.2 | Companion example | The worked example of the companion pattern: one client's specific content types, registered without forking Scout Core. | `scout-core` |

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
2. `scout-build` (the dashboard hub, which turns the rest on)
3. The Core features the client's plan uses, from Scout → Scout tools → Features
4. The client companion plugin, if the site has its own types (`scout-rvpark`
   is the reference copy)

Then open Scout → Business and fill in the name, address, phone, and
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
