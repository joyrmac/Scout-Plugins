# Scout Cards

Digital business cards and a social "link in bio" page, built into the client's
own website. The Blinq-style experience with no app and no monthly fee: every
tap lands on the client's site and counts in their analytics.

## What it adds

| Address | What it is |
|---|---|
| `/card/` | The main card: logo or photo, intro, **Save contact**, Call, Text, Email, Website, then "Who would you like to talk to?" listing each person |
| `/card/<person>/` | One card per person, with their photo, title, and a line in their own voice |
| `/card/save/`, `/card/<person>/save/` | Downloads the phone contact (vCard 3.0 with a 400px JPEG photo) |
| `/card/share/`, `/card/<person>/share/` | Share mode for the card's owner: a big QR code plus Copy link, Text, Email, WhatsApp, LinkedIn. Saved to the home screen, it opens like an app (uses the Site Icon) |
| `/links/` | Link in bio for Instagram, Facebook, Threads, LinkedIn. The newest blog post leads automatically |

No WordPress Pages are needed; the plugin owns these addresses. The words
`card` and `links` are settings. Every page is `noindex, follow`.

## How it fits the platform

- **Reads the business identity from Scout Core** (name, phone, email, city,
  profile URLs). Typed once in Scout -> Business, used here. Any field can be
  overridden on the Cards & Links screen. Works without Scout Core too.
- **Dashboard:** Scout -> **Cards & Links** when Scout Core is active, otherwise a
  top-level **Site Build** section (the dashboard standard in the Scout playbook).
- **Matches each brand:** the page loads the theme's stylesheet, so the card uses
  the site's own font. The accent color and light or dark mode are settings.
  Card components are styled under `.scc`, so theme CSS cannot reshape them.
- **SEO:** the card pages print their own title, canonical, and robots tags and
  switch off Scout Core's head output there, so no page gets a wrong canonical.

## Setting it up on a site

1. Install and activate (Plugins -> Add New -> Upload, or let a release arrive).
2. Check **Scout -> Business** is filled in, and set a **Site Icon** (Appearance ->
   Customize, or Settings -> General) for the logo and home-screen icon.
3. **Scout -> Cards & Links:** pick the accent color and mode, write the main intro,
   add each person (name, link name, title, email, intro, "why talk to them," tags,
   headshot), and set the link rows. Each card has an optional **Spotlight** (a heading,
   a short note, and one link row) for a product, booking page, or second business.
4. Clear the host cache, then run the QA checklist in the Scout playbook
   (`ops/playbook/addon-digital-business-cards.md` in Scout-Media-Raleigh).

If a WordPress Page already uses `/card/` or `/links/`, the screen warns you;
change the address in **Addresses** or rename the Page.

## Settings

One option, `scout_cards_settings`. Blank fields fall back to Scout Core's
business identity, then to the site name and tagline. **Reset to defaults** deletes
the option.

## Files

- `includes/settings.php`: defaults, identity, people, socials, link rows
- `includes/routes.php`: rewrite rules and dispatch
- `includes/vcard.php`: the vCard builder and photo conversion
- `includes/render.php`: the card, share, links pages, and the web app manifest
- `includes/admin.php`: the Cards & Links screen
- `assets/cards.css`: card styles (scoped to `.scc`)
- `assets/qrcode.min.js`: qrcode-generator 1.4.4 by Kazuhiko Arase (MIT)
