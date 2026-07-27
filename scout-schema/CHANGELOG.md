# Scout Schema — Changelog

## 0.1.0 — 2026-06-18

Initial scaffold.

- Single JSON-LD `@graph` printed in `wp_head`.
- `#business` node (LocalBusiness or the configured subtype) from the Scout
  business identity: name, address, geo, telephone, email, priceRange, sameAs,
  logo.
- `#website` node with publisher reference and a SearchAction.
- `AggregateRating` + `Review` nodes from testimonials, `itemReviewed` → `#business`.
- `FAQPage` on the FAQ hub page/archive from the FAQ post type.
- `BlogPosting` on single posts, publisher → `#business`.
- Filters: `scout_schema_graph`, `scout_schema_aggregate_rating`,
  `scout_schema_review_limit`, `scout_schema_is_faq_page`.
- Review/aggregate cached in a transient, cleared on testimonial or business change.

### Known follow-ups
- Structured `openingHoursSpecification` (needs structured hours input in
  Scout Core rather than the current free-text field).
