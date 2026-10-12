# Scout Reviews: Changelog

## 0.4.0 (2026-10-12)

Ask for reviews: one short link, a QR code, printable cards, and ready-to-send messages.

- **`/review/` on the client's own domain** sends people straight to the
  "leave a review" link from Settings (Google by default), or to a simple page
  with an equal button for each platform. The address is a setting. Printed
  cards point here, so a changed review link never breaks a card.
- **Reviews > Ask for reviews.** Copy the link, download the QR code (SVG for
  printers, PNG), print a 4 by 6 counter card, a sheet of 10 business cards,
  or a fold-in-half table tent, and copy a text message and email with the
  business name and link filled in.
- **Visit counts by month and source** (QR, text, email, other). Link-preview
  bots and signed-in staff are not counted. Totals only; no visitor data is
  stored.
- **No review gating and no incentive wording,** by design: every client
  gets the same ask. The page lists the rules, including the state bar check
  for law firms.
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
