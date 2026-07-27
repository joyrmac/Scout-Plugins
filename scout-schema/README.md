# Scout Schema

Emits one connected JSON-LD `@graph` from Scout Core data. This is the in-house
replacement for a plugin's generic schema: every node is generated in PHP from
real content and tied together by stable `@id`s, which is what makes Google and
AI engines read the site as a single entity.

Requires **Scout Core** active (it reads the business identity and the
Testimonial/FAQ types). WordPress 6.5+, PHP 8.0+.

---

## What it outputs

| Node | When | Source |
|---|---|---|
| `LocalBusiness` (or your subtype) `#business` | Every page | Settings → Scout Business (NAP, geo, profiles) |
| `WebSite` `#website` | Every page | Site title + `home_url()`, publisher → `#business` |
| `AggregateRating` + `Review` | Every page (rating); reviews capped | Testimonials with ratings |
| `FAQPage` | FAQ hub page / archive | FAQ post type (title = question, content = answer) |
| `BlogPosting` | Single posts | The post (headline, dates, author, image), publisher → `#business` |

The business `@id` is `home_url('/#business')` and never changes, so every other
node can reference it. Set the **schema type** field in Scout Business to the
right subtype per client: `Campground` for the RV park, `LegalService` for a
firm, `Restaurant` for a venue.

---

## Validate it

After activating, open any page and run the URL through Google's
[Rich Results Test](https://search.google.com/test/rich-results) and the
[Schema Markup Validator](https://validator.schema.org/). You should see one
graph with the business, website, and any page-specific nodes connected.

---

## Filters (extend without editing the plugin)

- `scout_schema_graph` — the whole node array before output. Add or remove nodes.
- `scout_schema_aggregate_rating` — the aggregate rating array. Return `null` to
  suppress it (see the policy note below).
- `scout_schema_review_limit` — how many `Review` nodes to embed (default 10).
- `scout_schema_is_faq_page` — return `true` to mark any page as the FAQ hub.

---

## One policy rule (from the playbook's judgment calls)

**Never publish an `aggregateRating` that doesn't match the live Google profile.**
This plugin computes the aggregate from the testimonials you enter, which is
honest, but self-served ratings can also be a structured-data risk for some
business types. If the on-site rating would not match the Google Business
Profile, suppress it:

```php
add_filter( 'scout_schema_aggregate_rating', '__return_null' );
```

---

## Performance

The review query is cached in a transient for an hour and cleared automatically
when a testimonial is saved or deleted, or when the business identity changes.
