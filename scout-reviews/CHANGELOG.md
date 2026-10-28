# Scout Reviews: Changelog

## 0.5.4 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 0.5.3 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.5.2 (2026-10-13)

- **Fix: business cards print on one page in Safari.** The ten-card sheet filled a letter page exactly, so Safari's print margins pushed the last row onto a second page. Printed cards now come out at 94% size (about 3.3 x 1.9 in) and always fit one page. Screen previews are unchanged.

## 0.5.1 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 0.5.0 (2026-10-13)

- **The review page and printed cards wear the client's brand.** Ask for reviews > Settings adds page background, text color, and heading, body and small-label fonts next to the brand color. The /review/ and /review/note/ pages use them, text colors adjust to a light or dark background, and the button text picks white or near-black for contrast. The counter card, business cards and table tent print in the same fonts, with the brand color as the headline (deepened if too pale for paper). Fonts come from a fixed list of Google Fonts with each one's real weights, so the font request never fails. Defaults stay Scout's own look, and a client theme can supply its own with the `scout_reviews_brand` filter.

## 0.4.13 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.4.12 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.4.11 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.4.10 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.4.9 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.

## 0.4.8 (2026-10-13)

- **New 5-star Google reviews can go live on their own.** Review settings > "New 5-star reviews": when on, each new 5-star review from the Google sync is published on the pages it matches (topic keywords). Lower ratings and notes from the review page still wait in the Review inbox. Off by default; leave it off for law firms.

## 0.4.7 (2026-10-13)

- **TripAdvisor is a review source**, with stars and a "View on TripAdvisor" link. For RV parks, restaurants and venues.
- **Choose who gets private notes.** Ask for reviews > Page settings > "Send private notes to". Empty keeps the site admin email.
- **The badge on direct reviews can be renamed** with the `scout_reviews_direct_badge` filter (default "Client testimonial"), so a park can say "Guest".

## 0.4.6 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 0.4.5 (2026-10-12)

- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

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
