# Scout Reviews

Reviews from Google, Facebook, Yelp, Clutch, and anywhere else, kept in one
place and shown on the site in a custom design. Every card says where the
review came from and links to the original. The rating summary uses each
platform's real totals.

Standalone: does not need Scout Core.

## Using it

Where the screens are: on a site with a Build section (Scout Build, Recon Build,
Site Build), every Reviews screen sits inside it: Reviews, New reviews, Ask for
reviews and Review settings. On any other site the plugin keeps its own Reviews
menu, with the same screens under it.

1. **Review settings.** For each platform you're on, enter the overall
   rating and total review count exactly as the platform shows them, plus the
   profile link and the "leave a review" link.
2. **Reviews > Add review.** Reviewer name as the title, the review text word
   for word, then source, stars, link, and date. Publish to show it; save as a
   draft to hide it. Check **Featured** for the home page picks. Set
   **Page Attributes > Order** to control the order (lower shows first).
3. **Put it on a page.** Add the **Scout Reviews** block, or a shortcode:

| Shortcode | Shows |
|---|---|
| `[scout_reviews]` | Rating summary and every published review |
| `[scout_reviews featured="1" count="3"]` | Three featured reviews |
| `[scout_reviews source="google" layout="row"]` | Google reviews in a sideways row |
| `[scout_reviews summary="0" min_rating="5"]` | Five-star reviews, no summary |
| `[scout_reviews topic="law-firms" count="3"]` | Reviews for one topic (featured ones until it has some) |
| `[scout_review_summary]` | Only the rating summary |

Options: `layout` (grid, row, list), `count`, `source`, `featured`,
`min_rating`, `summary`, `topic`, `fallback`.

## New reviews and topics (0.3.0)

New Google reviews land in **New reviews**. Each card has the pages it
belongs on pre-checked (from each topic's keywords). Click **Add to site** and
it shows on those pages; **Skip** keeps it as a hidden draft.

Topics live at **Reviews > Topics**. Each one is a place reviews show on the
site, usually one per page, with keywords that drive the inbox suggestions.

## Ask for reviews (0.4.0)

**Ask for reviews** gives the business one review link on its own
domain and everything needed to share it:

| Address | What it shows |
|---|---|
| `/review/` | The review page: the ask, a button to the main platform, smaller buttons for the others, and a quiet private note link |
| `/review/?via=qr` | The same page, counted as a QR scan (also `text`, `email`, `card`) |
| `/review/note/` | The private note form. Entries land in New reviews and are emailed to the note address set in Reviews, or to the notice address in Business info when that is blank (filter `scout_reviews_note_recipient`) |

The screen also has the QR code (SVG, PNG), three printable card layouts, a
text message and an email ready to copy, and monthly visit counts by source.
Setting "Where the link goes" to the main platform skips the page entirely.

Every client gets the same page on purpose: asking only happy clients (review
gating) breaks Google's rules and FTC guidance, and so does offering anything
in return. The private note link sits below the review buttons, never in
their place. A note can go on the site only when the client ticked the OK box.
Law firms check their state bar's rules before sending requests.

The page loads Hanken Grotesk and Geist Mono from Google Fonts; return an
empty string from `scout_reviews_page_fonts_url` to use system fonts. If visit
counts look low on WP Engine, exclude `/review/` from the page cache.

## Theme integration

One call per spot where reviews belong. It prints nothing when there is
nothing to show, so it never leaves an empty section:

```php
<?php
if ( function_exists( 'scout_reviews_slot' ) ) {
	scout_reviews_slot( array(
		'topic'  => 'law-firms',          // Default: the page's slug ("home" on the front page).
		'count'  => 3,
		'before' => '<section class="section"><div class="container"><h2>What firms say</h2>',
		'after'  => '</div></section>',
	) );
}
```

Declare the theme's slots once in `functions.php` so the topics exist before
anyone visits the pages:

```php
add_filter( 'scout_reviews_slots', function ( $slots ) {
	$slots['law-firms'] = array( 'label' => 'Law firms', 'keywords' => array( 'law', 'attorney', 'lawyer' ) );
	return $slots;
} );
```

Other options: `layout` (grid, row, list), `summary` (rating line, default on),
`fallback` ("featured" by default; "none" shows nothing when the topic is
empty), `label` (topic name if it has to be created).

## Honesty rules built in

These keep client sites on the right side of the FTC's 2024 review rule and,
for law firms, state bar advertising rules.

- **Each card is labeled by source** ("Google review") and links to the
  original. Nothing is labeled "verified": platforms do not verify that a
  reviewer was a client.
- **The summary never averages the hand-picked reviews.** It shows the totals
  typed into Settings (or synced from Google in 0.2.0), so a curated wall can
  never overstate the rating.
- **Review text is shown as written.** Edit nothing but obvious formatting.
- **Optional disclaimer** under every reviews section, for law firms whose bar
  requires one with testimonials.
- **No review schema.** Google has not shown star ratings for a business's
  reviews of itself since 2019, so the markup would add risk and no benefit.

## Google sync (0.2.0)

Once Google approves API access ([setup steps](docs/google-business-profile-api.md)):

1. **Review settings > Bring in Google reviews.** Paste the OAuth client ID and
   secret, or define `SCOUT_REVIEWS_GOOGLE_CLIENT_ID` and
   `SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET` in `wp-config.php` (better).
2. **Connect Google** and sign in with the account that manages the profile.
3. **Pick the business.** The first sync runs right away, then daily.

New reviews arrive as drafts with a count on the Reviews menu. Publish the
ones you want. Google keeps control of the name, text, stars, and date; a
review deleted on Google comes off the site on the next sync.

## What this feature needs from the theme

- **DESIGN.md:** none yet. On the site, the stylesheet reads the theme's own
  CSS custom properties when they exist (`--surface`, `--line`, `--text-2`,
  `--text-3`, `--accent-text`, `--font-mono`, `--r-card`) and otherwise mixes
  shades from the page's text color, so it fits light and dark themes. The
  stars are a fixed amber, `#fbb024`.
- **Where it lands:** where the **Scout Reviews** block, `[scout_reviews]`,
  `[scout_review_summary]` or `scout_reviews_slot()` sits (Theme
  integration, above). A slot prints nothing when there is nothing to show,
  so the theme passes its section wrapper as `before` and `after`.
- **The review page** (`/review/` and `/review/note/`) is its own page,
  outside the theme's templates. Its look comes from Review settings
  (`background`, `text_color`, `accent`, `heading_font`, `body_font`,
  `label_font`), whose defaults a theme can set with the `scout_reviews_brand`
  filter. The logo at the top is the site icon.
- **scout-setup.json:** the `reviews` section, filled in by Scout Build where
  the setting is blank: `business`, `headline`, `ask`, `photo`,
  `card_headline`, `google_review_url`, and the look keys `background`,
  `text_color`, `accent`, `heading_font`, `body_font`, `label_font`.
- **Restyling today:** override the `--sr-*` variables on `.scout-reviews`,
  copy `templates/section.php`, `summary.php` or `card.php` to
  `{theme}/scout-reviews/`, or turn the stylesheet off in Settings or with
  `add_filter( 'scout_reviews_load_styles', '__return_false' );`.

## Filters

- `scout_reviews_sources`: add or rename a source
  (`slug => [ label, stars, sync ]`).
- `scout_reviews_load_styles`: load the built-in stylesheet (bool).
- `scout_reviews_slots`: declare the theme's review slots
  (`slug => [ label, keywords ]`).

## Roadmap

- **0.3.0:** Facebook Page recommendations sync.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| What a person sends on the private note form: their name, their note, a star rating (optional), their business name (optional), whether they said it is OK to show it on the website, and the date it was sent (When someone sends the note form at /review/note/. A note is only shown on the website if the person ticked the OK box.) | This website's database, as draft reviews in Reviews > New reviews | Until someone deletes it | Emailed to the site's own address (the note email set in Reviews, or the notice address in Business info) |
| A scrambled (hashed) form of the visitor's IP address, with a count of notes sent from it, used to limit notes to five an hour (When someone sends the private note form.) | A temporary record in this website's database | One hour | Nobody |
| A count of visits to the review page, kept as monthly totals by how the link was shared (QR code, text, email, card or plain link). Nothing about the visitor is saved with it. (When someone opens the review page (/review/). Search engines, link previews and signed-in staff are not counted.) | This website's database, in the plugin's settings | The last 13 months of totals | Nobody |
| Public Google reviews of the business, copied from its Google Business Profile: the reviewer's name as Google shows it (or "A Google user"), the review text, star rating, date, and the address of the reviewer's Google profile photo (Only when Google is connected in the Reviews settings. Reviews with stars but no words are not copied.) | This website's database, as reviews. New ones arrive as drafts. | Until someone deletes it. A daily sync updates each one from Google. A review removed from Google is taken off the site and kept as a draft. | Nobody |
| Reviews that site staff copy in by hand from other review sites (such as Yelp, Facebook or BBB): the reviewer's name, the review text, stars, date, a link to the review and an optional reviewer detail (When site staff add them.) | This website's database, as reviews | Until someone deletes it | Nobody |
| The visitor's IP address and browser details, seen by Google when a review card shows a Google reviewer's photo (When a page shows a Google review that has a profile photo.) | Not kept by this website. The photo loads straight from Google's servers, without telling Google which page it is on. | Not kept by this website | Google |
| The visitor's IP address and browser details, seen by Google Fonts when the review pages load their fonts (On the /review/ and /review/note/ pages, when a font from the list is chosen in the Reviews settings (Hanken Grotesk and Geist Mono are the defaults).) | Not kept by this website | Not kept by this website | Google Fonts |

Deleting the plugin keeps the reviews, notes and visit totals unless "Remove all reviews and settings when the plugin is deleted" is ticked. The private note form asks for no email, so the site's privacy tools cannot find a note; staff delete one by hand in Reviews > New reviews.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
