# Scout Reviews: Changelog

## 0.4.4 (2026-10-12)

- **Printable cards print on one page in every browser.** Print now opens
  only the chosen sheet in a hidden frame, so Safari no longer spreads the
  business cards over five pages.
- **The previews look right in Safari.** They are scaled with a transform
  instead of zoom, so the QR code and text stay inside each card.
- **Shorter name on cards and messages.** When no business name is set, the
  site title is used without its tagline: "Smith Law | Raleigh, NC" prints as
  "Smith Law". Set a name under Ask for reviews, Page settings to override it.

## 0.4.3 (2026-10-12)

- **Reviews moved into the Build section.** On a site with Scout Build (or
  Recon Build, or Site Build), the Reviews screens now sit inside it with the
  other features: Reviews, Review inbox, Ask for reviews and Review settings.
  The separate Reviews menu in the sidebar is gone on those sites. Old
  bookmarks still work and land on the new screen. Sites without a Build
  section keep the Reviews menu as before.

## 0.4.2 (2026-10-12)

- **A new look for every settings screen.** The plugin's screens now share
  one Scout design: a clear page header with the section name, settings
  grouped into rounded boxes, pill buttons, and a short sign-off footer with
  how to reach us. On client sites the header reads "Site Build" instead of
  "Scout". Nothing about how the settings work changed.

## 0.4.1 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 0.4.0 (2026-10-12)

Ask for reviews: a review page on the client's own domain, a private note
form, a QR code, printable cards, and ready-to-send messages.

- **`/review/`** is a dark phone page (Scout features spec 5.21): business
  name, optional team photo, the ask line, a "Leave a Google review" button,
  smaller buttons for any other platform with a link, and a quiet "Prefer to
  send us a note privately?" link at the bottom. A setting skips the page and
  goes straight to the main platform instead. The address is a setting.
  Printed cards point here, so a changed review link never breaks a card.
- **`/review/note/`**: stars (optional), the note, name, business (optional)
  and an "OK to show this on {domain}" box. Notes arrive in Reviews > Inbox as
  drafts and by email. A note without that OK can never be published: the
  inbox only offers "Mark as read", and pressing Publish in the editor keeps
  it a draft. Spam: hidden field, signed timestamp, five notes an hour per
  connection.
- **Reviews > Ask for reviews**: copy the link, download the QR code (SVG,
  PNG), print a 4 by 6 counter card, a sheet of 10 business cards, or a table
  tent ("Tell Google how we did."), and copy a text and an email with the
  business name and link filled in. Monthly visit counts by source (QR, text,
  email, other); link-preview bots and signed-in staff are not counted, and no
  visitor data is stored.
- **Reviews > Inbox** shows notes from the website with a "New · from the
  website" pill and their consent state, plus "Ask for a Google review too".
- Both screens use the light product look (spec 4.3).
- **No review gating and no incentive wording,** by design: every client sees
  the same page and the same ask. The page lists the rules, including the
  state bar check for law firms.
- `assets/qrcode.min.js`: qrcode-generator 1.4.4 by Kazuhiko Arase (MIT),
  the same file Scout Cards uses.

## 0.3.0 (2026-10-01)

Review inbox, topics, and theme slots: reviews land on the right pages in one click.

- **Reviews > Inbox.** New Google reviews wait here as cards. "Add to site"
  publishes one with the checked topics; "Skip" keeps it as a hidden draft.
  "Add all" publishes every waiting review with its suggested topics. The
  inbox count shows on the menu.
- **Topics** (`scout_review_topic`): which pages a review shows on. Each topic
  has keywords; the inbox pre-checks topics whose keywords appear in the
  review. Edit keywords at Reviews > Topics.
- **Theme slots.** `scout_reviews_slot()` shows the reviews for that spot, with
  optional `before`/`after` markup printed only when there are reviews. A
  theme declares its slots (label + keywords) with the `scout_reviews_slots`
  filter; an undeclared slot registers itself as a topic on first render.
- `topic` and `fallback` options on `[scout_reviews]`, plus a Topic field on
  the block. A topic with no reviews yet shows featured reviews instead
  (`fallback="none"` shows nothing).
- The stylesheet loads in `<head>` when a page's content uses the shortcode or
  block, so reviews never restyle after they paint.
- A slot that registers its own topic uses the theme's declared name when
  there is one.

## 0.2.0 (2026-10-01)

Google reviews sync.

- Reviews > Settings has a Google box: save the OAuth client (or define
  `SCOUT_REVIEWS_GOOGLE_CLIENT_ID` and `SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET` in
  `wp-config.php`), Connect Google, pick the business, Sync now, Disconnect.
- Syncs daily through the Business Profile API (v4 reviews). New reviews
  arrive as drafts and are counted on the Reviews menu and dashboard until
  published or trashed.
- Google owns a synced review's name, text, stars, and date; you choose
  whether it shows, the reviewer detail, Featured, and the order. A review
  deleted on Google is unpublished here and marked removed.
- Star-only reviews count in the totals but are not imported. Google's
  machine translations are stripped so the reviewer's own words show.
- The Google rating, review count, Maps link, and "leave a review" link fill
  in automatically and lock while synced.
- Reviewer profile photos show on synced cards.
- Uninstall always removes the Google sign-in; deactivation stops the daily
  sync.

## 0.1.0 (2026-10-01)

First release. Manual entry for every source; automatic Google sync comes in 0.2.0.

- `scout_review` post type: reviewer name, review text word for word, source,
  star rating, link to the original, date posted, optional reviewer detail,
  featured flag, and display order. Published shows; draft hides.
- Sources: Google, Facebook, Yelp, Clutch, UpCity, DesignRush, BBB, Avvo, and
  direct client testimonials. Extend with the `scout_reviews_sources` filter.
- Reviews > Settings: each platform's real overall rating and review count,
  profile and "leave a review" links, an optional disclaimer, a stylesheet
  toggle, and an opt-in purge on delete.
- Display: `[scout_reviews]`, `[scout_review_summary]`, and the
  `scout/reviews` block (grid, scrolling row, or single column; filter by
  source, featured, minimum rating, count).
- Cards label each review by source ("Google review") and link to the
  original ("View on Google"). The summary uses the platform's own totals,
  never an average of the hand-picked reviews.
- Theme overrides: `{theme}/scout-reviews/section.php`, `summary.php`,
  `card.php`; restyle with the `--sr-*` CSS variables or turn the stylesheet
  off with `scout_reviews_load_styles`.
