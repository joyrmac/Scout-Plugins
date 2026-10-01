# Scout Reviews: Changelog

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
