# Scout Cards

Digital business cards and a social "link in bio" page, built into the client's
own website. The Blinq-style experience with no app and no monthly fee: every
tap lands on the client's site and counts in their analytics.

## What it adds

| Address | What it is |
|---|---|
| `/card/` | The main card: logo or photo, intro, **Save contact**, Call, Text, Email, Website, then "Who would you like to talk to?" listing each person |
| `/card/<person>/` | One card for each person in People with Card on, with their photo, title, and a line in their own voice |
| `/card/save/`, `/card/<person>/save/` | Downloads the phone contact (vCard 3.0 with a 400px JPEG photo) |
| `/card/share/`, `/card/<person>/share/` | Share mode for the card's owner: a big QR code plus Copy link, Text, Email, WhatsApp, LinkedIn. Saved to the home screen, it opens like an app (uses the Site Icon) |
| `/links/` | Link in bio for Instagram, Facebook, Threads, LinkedIn. The newest blog post leads automatically |

No WordPress Pages are needed; the plugin owns these addresses. The words
`card` and `links` are settings. Every page is `noindex, follow`.

## How it fits the platform

- **Reads the business identity from Scout Core** (name, phone, email, city,
  profile URLs). Typed once in Scout -> Business, used here. Any field can be
  overridden on the Cards and links screen. Works without Scout Core too.
- **Dashboard:** Scout -> **Cards and links** when Scout Core is active, otherwise a
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
3. **People:** add each person (Add a person) and switch on **A business card** under
   Shows on. Their name, title and photo live there, and the **Business card** box
   holds their intro, "why talk to them," tags, card phone and card email.
4. **Scout -> Cards and links:** pick the accent color and mode, open **Edit** on the
   main card (its name, email, logo and intro come from Business info unless you use
   something else), open **Card settings** on each person to set their link name and
   what the card shows, and set the link rows. Each card has an optional **Spotlight**
   (a heading, a short note, and one link row) for a product, booking page, or second
   business: under **Extras** on the main card and **More** on each person.
5. Clear the host cache, then run the QA checklist in the Scout playbook
   (`ops/playbook/addon-digital-business-cards.md` in Scout-Media-Raleigh).

If a WordPress Page already uses `/card/` or `/links/`, the screen warns you;
change the address in **Addresses** or rename the Page.

## Cards read from People

One profile per person. **Scout People** (`scout-team`) holds who each person is and
what their card says: name, title, photo and its alt text, and in its **Business card**
box the intro, "why talk to them," tags, card phone and card email, plus the profile
links and short bio. This plugin keeps each card's own settings.

| From People (`Scout_Team::card()`) | Kept in Cards, per card |
|---|---|
| Name, title, photo and alt text | Link name (the `/card/<link name>/` address) |
| Card phone and card email (blank uses the business phone and email) | Show on the card: Phone, Email, Intro, Tags, Profile links, Short bio, Save contact |
| Intro, "why talk to them," tags (empty uses the card's own saved words) | Share picture and Spotlight (under More) |
| Profile links and short bio (shown only when switched on) | |

- **Who gets a card:** anyone live in People with **A business card** switched on.
  Switch it off, or take them off the site, and their card address answers "not
  found", the same as a profile page that is off. The People row tag "Card" shows
  who has it on.
- **The Cards screen** lists those people (photo, name, card address, Share mode, QR
  code), with **Edit in People** for what the card says and **Card settings** for
  switches only: the link name and Show on the card, then a closed **More** with the
  share picture and Spotlight. No words are typed on the Cards screen. **Add a person** opens the People walkthrough with Card on
  (`?page=scout-people&add=1&card=1`).
- **The switches** start the way cards always worked: phone, email, intro, tags and
  Save contact on, profile links and short bio off. With Save contact off the button goes, and the
  `/save/` address still works.
- **Fallback:** each card record keeps the name, title, email, photo, intro, "why talk
  to them" and tags it had. With Scout People off, or the profile deleted, the card
  shows those, exactly as before. An intro, why or tags that is empty in People also
  uses the card's own.
  The screen then shows the old fields (People off) or a read-only row with "Take
  this card off" (profile deleted).

### The main card and Business info

With People on, the main card (`/card/`) takes its **name**, **email** and **logo or
photo** from Business info (Scout Core), and its **intro** from Business info's
description when Business info has one (otherwise the intro is a card field). Each has
a closed **Use something else** with the card's own value; empty means "use Business
info". The saved keys are the same (`name`, `email`, `photo_id`, `logo_id`,
`team_intro`). An older save that copied Business info in shows as empty. The logo is
read from Business info's `logo_id` or `logo` (an attachment ID) when Scout Core has
one; until then the Site Icon shows, as before. **Heading above the people** and the
**Spotlight** sit under **Extras**. The rest of the business details (phone, city,
socials, footer line, contact note) stay under More options.

### The one-time move

On the first page load after this version with Scout People on (and People's own move
done), and never on a form post, every card person is linked to a People profile,
recorded by the `scout_cards_people_db` option:

1. **Match**, in order: the profile tied to the login whose email is the card's email,
   then the profile with that email or card email, then the profile with exactly that
   name. A live card only matches a live profile. Trashed profiles never match.
2. **Matched:** the card gets that profile's ID (`profile_id` on its record). Only what
   the profile is missing is copied in: title, card email, photo. A People value is
   never overwritten. Card is switched on.
3. **No match:** a new profile is made with the card's name, title, card email and
   photo. It is live only if cards were on, so the card keeps working. It shows on the
   card only (own page, team page, byline, speakers and chat off), so nothing new
   appears on the site. It joins the Team group when People had no one in Team yet or
   a card person matched someone in Team; otherwise it has no group. Filter
   `scout_cards_people_in_team` to decide.
4. **Card on with no card yet:** before this version the People switch did nothing.
   Anyone who had it on with no card has it switched off, so no new card appears.
5. **The note:** the Cards screen shows once what happened ("Moved 3 card people into
   People."), names any card that now shows a value People already had, and anyone
   whose Card switch was turned off. Stored in `scout_cards_people_note` until shown.
6. **The card's words (`scout_cards_people_db` 2):** each linked card's intro, "why
   talk to them" and tags are copied into the profile's `card_intro`, `card_why` and
   `card_tags`, only where the profile field is empty. A People value is never
   overwritten, and the card keeps its own copy for the fallback. It waits for a Scout
   People that has the Business card box. Sites already past the first move run only
   this step. The note says how many profiles got them.

## Moving from a theme's own cards

The Scout Media theme drew its own cards before this plugin (`inc/card.php`, the
`scout_cards` option). Scout Cards stands down while the theme still draws them (its
`scout_cards_admin_page()` and `scout_card_people()` are both loaded); filter
`scout_cards_theme_has_cards` to decide. Scout Media 1.54.0 and later load their cards
only while this plugin is off, so activating the plugin takes over, with nothing
changing for visitors (Site Standard rule 20):

1. **The one-time copy** (`includes/theme-import.php`, gated by `scout_cards_theme_import`)
   reads what the theme's cards showed and fills only empty plugin settings. A value that
   matches Business info stays empty. Each theme card person becomes a card record, and the
   People move links it to a People profile. The theme's option is left alone.
2. **The theme's own card look** (`includes/render-theme.php`, setting `look` = `theme`)
   prints the theme's card markup, styled by the theme's own stylesheet, and hands the
   title, description, canonical and robots to the theme's head through
   `$GLOBALS['scout_seo']`. It renders as the page template, and loads the card and links
   addresses as the theme's `/card/` and `/links/` Pages, so head tags and body classes
   match. It is on only while the theme keeps `scout_card_settings()`; switch to the Scout
   Cards look under More options.
3. **Photos:** a person's photo comes from People. With no photo there, the theme look uses
   the theme's own `assets/img/team-<link name>.webp`, and the contact file uses
   `assets/img/card-photo-<link name>.jpg`, as the theme's cards did.

Release order: Scout Cards first (it rests while the old theme runs), then the theme.

## What this feature needs from the theme

- **DESIGN.md: none yet.** The card's own look takes its accent, font and light or dark mode from the Cards screen (`accent`, `font`, `mode`, default cerulean `#0E8FE6` and dark), printed as `--scc-accent` and `--scc-font`. Moving these to DESIGN.md is on the Site Standard list (gap 11).
- **Where it lands:** its own pages at `/card/`, `/card/<name>/`, each card's `/share/` page, and `/links/`. They are standalone, so the theme's header and footer do not print there. Nothing lands on the theme's other pages.
- **Logo:** the site icon (Settings → General) for the main card when no photo is set.
- **Photos and words:** each person's photo, intro, why talk to them and tags come from their People profile; the main card's name and email come from Business info.
- **Theme look (moving sites only):** while a theme still carries its own card settings (`scout_card_settings()`), "The theme's own card look" prints the theme's `bc-` markup inside the theme's header and footer, styled by the theme's stylesheet.
- Nothing else.

## Settings

One option, `scout_cards_settings`. Blank fields fall back to Scout Core's
business identity, then to the site name and tagline. **Reset to defaults** deletes
the option.

## Files

- `includes/settings.php`: defaults, identity, people (from People, else each card's saved details), socials, link rows
- `includes/people.php`: reading People, the card switches, and the one-time move
- `includes/routes.php`: rewrite rules and dispatch
- `includes/vcard.php`: the vCard builder and photo conversion
- `includes/render.php`: the card, share, links pages, and the web app manifest
- `includes/render-theme.php`, `includes/theme-template.php`: the theme's own card look
- `includes/theme-import.php`: the one-time copy from the theme's card settings
- `includes/admin.php`: the Cards and links screen
- `assets/cards.css`: card styles (scoped to `.scc`)
- `assets/qrcode.min.js`: qrcode-generator 1.4.4 by Kazuhiko Arase (MIT)

<!-- visitor-data:start -->
## Visitor data

This plugin keeps nothing about the people who visit the site and sets no cookies.

The card, contact file and link pages only show the team's own details: each person's name, title, photo, card phone, card email, intro, "why talk to them" line and tags come from their People profile, and only for people with Card switched on. The QR code is drawn in the visitor's browser, and the share and copy buttons use the visitor's own device; nothing is sent back to the site.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
