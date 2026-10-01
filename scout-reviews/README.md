# Scout Reviews

Reviews from Google, Facebook, Yelp, Clutch, and anywhere else, kept in one
place and shown on the site in a custom design. Every card says where the
review came from and links to the original. The rating summary uses each
platform's real totals.

Standalone: does not need Scout Core.

## Using it

1. **Reviews > Settings.** For each platform you're on, enter the overall
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

## Inbox and topics (0.3.0)

New Google reviews land in **Reviews > Inbox**. Each card has the pages it
belongs on pre-checked (from each topic's keywords). Click **Add to site** and
it shows on those pages; **Skip** keeps it as a hidden draft.

Topics live at **Reviews > Topics**. Each one is a place reviews show on the
site, usually one per page, with keywords that drive the inbox suggestions.

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

1. **Reviews > Settings > Google reviews sync.** Paste the OAuth client ID and
   secret, or define `SCOUT_REVIEWS_GOOGLE_CLIENT_ID` and
   `SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET` in `wp-config.php` (better).
2. **Connect Google** and sign in with the account that manages the profile.
3. **Pick the business.** The first sync runs right away, then daily.

New reviews arrive as drafts with a count on the Reviews menu. Publish the
ones you want. Google keeps control of the name, text, stars, and date; a
review deleted on Google comes off the site on the next sync.

## Design

The stylesheet reads Scout design tokens when the theme has them (`--surface`,
`--line`, `--text-2`, `--accent-text`, `--font-mono`, `--r-card`) and
otherwise mixes colors from the page's text color, so it fits light and dark
themes. To restyle:

- Override the `--sr-*` variables on `.scout-reviews`.
- Replace markup by copying `templates/section.php`, `summary.php`, or
  `card.php` to `{theme}/scout-reviews/`.
- Turn the stylesheet off in Settings or with
  `add_filter( 'scout_reviews_load_styles', '__return_false' );`.

## Filters

- `scout_reviews_sources`: add or rename a source
  (`slug => [ label, stars, sync ]`).
- `scout_reviews_load_styles`: load the built-in stylesheet (bool).
- `scout_reviews_slots`: declare the theme's review slots
  (`slug => [ label, keywords ]`).

## Roadmap

- **0.3.0:** Facebook Page recommendations sync.
