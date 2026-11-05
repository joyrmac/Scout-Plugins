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
   Shows on. Their name, title, photo, card phone and card email live there.
4. **Scout -> Cards and links:** pick the accent color and mode, write the main intro,
   open **Card settings** on each person to set their link name, intro, "why talk to
   them," tags, share picture and what the card shows, and set the link rows. Each card
   has an optional **Spotlight** (a heading, a short note, and one link row) for a
   product, booking page, or second business.
5. Clear the host cache, then run the QA checklist in the Scout playbook
   (`ops/playbook/addon-digital-business-cards.md` in Scout-Media-Raleigh).

If a WordPress Page already uses `/card/` or `/links/`, the screen warns you;
change the address in **Addresses** or rename the Page.

## Cards read from People

One profile per person. **Scout People** (`scout-team`) holds who each person is:
name, title, photo and its alt text, card phone, card email, profile links and short
bio. This plugin keeps each card's own look and links settings.

| From People (`Scout_Team::card()`) | Kept in Cards, per card |
|---|---|
| Name, title, photo and alt text | Link name (the `/card/<link name>/` address) |
| Card phone and card email (blank uses the business phone and email) | Intro, "why talk to them," tags |
| Profile links and short bio (shown only when switched on) | Share picture and Spotlight |
| | Show on the card: Phone, Email, Profile links, Short bio, Save contact button |

- **Who gets a card:** anyone live in People with **A business card** switched on.
  Switch it off, or take them off the site, and their card address answers "not
  found", the same as a profile page that is off. The People row tag "Card" shows
  who has it on.
- **The Cards screen** lists those people (photo, name, card address, Share mode, QR
  code), with **Edit in People** for their details and **Card settings** for the
  card's own settings. **Add a person** opens the People walkthrough with Card on
  (`?page=scout-people&add=1&card=1`).
- **The switches** start the way cards always worked: phone, email and Save contact
  on, profile links and short bio off. With Save contact off the button goes, and the
  `/save/` address still works.
- **Fallback:** each card record keeps the name, title, email and photo it had. With
  Scout People off, or the profile deleted, the card shows those, exactly as before.
  The screen then shows the old fields (People off) or a read-only row with "Take
  this card off" (profile deleted).

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
- `includes/admin.php`: the Cards and links screen
- `assets/cards.css`: card styles (scoped to `.scc`)
- `assets/qrcode.min.js`: qrcode-generator 1.4.4 by Kazuhiko Arase (MIT)

<!-- visitor-data:start -->
## Visitor data

This plugin keeps nothing about the people who visit the site and sets no cookies.

The card, contact file and link pages only show the team's own details: each person's name, title, photo, card phone and card email come from their People profile, and only for people with Card switched on. The QR code is drawn in the visitor's browser, and the share and copy buttons use the visitor's own device; nothing is sent back to the site.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
