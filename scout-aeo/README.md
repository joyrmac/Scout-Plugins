# Scout AI Search (`scout-aeo`)

Gets a client site ready for AI search: ChatGPT, Google's AI answers,
Perplexity, Claude. Works on any theme, with or without Scout Core.

## What it does

| Part | What it does | Where |
|---|---|---|
| Crawler access | Names the AI search crawlers and the AI training crawlers in `robots.txt`, each with its own on/off switch (both on by default). Only on the live site: staging, `*.wpengine.com`, local hosts and sites hidden from search are left alone. | `includes/class-scout-aeo.php` |
| Instant updates | IndexNow: when a public page is published or updated, its address goes to Bing and Copilot right away. Live site only; pages hidden from search are skipped. Key served at `/{key}.txt`. On by default. | `includes/class-scout-aeo-indexnow.php` |
| `/llms.txt` | A plain-text guide AI tools read: the business summary, contact line (from Scout Core when it runs), the main pages, recent posts, and every question answered on the site. Noindexed and private pages stay out. | `includes/class-scout-aeo.php` |
| Questions and answers | A "Questions this page answers" box on every page and post (up to 8). Answers print at the end of the content, or wherever `[scout_faq]` sits, as `<details>` with an `h2`/`h3` outline. `FAQPage` JSON-LD goes out only for a page that shows the answers: inside Scout Core's schema graph when Core is active (merged into Core's own FAQPage on its FAQ hub, so a page never carries two), or as its own block when Core is not active. | `includes/class-scout-aeo-faq.php` |
| Settings | **AI search**, nested in the site's Build section (Scout Build hub, theme Build menu, or Scout Core), otherwise under Settings. `manage_options` only. | `includes/class-scout-aeo-admin.php` |

The Site Build hub (`scout-build` 0.2.0+) scores the site on its **Ready for
AI search** card and counts pages with answers by the `_scout_aeo_faqs` meta key.

## Hooks

- `scout_aeo_is_live` (bool): override the live-site test.
- `scout_aeo_llms_txt` (string): replace or extend the `/llms.txt` body. The
  Scout Media theme uses it to keep its hand-written guide.
- `scout_aeo_llms_pages` (WP_Post[]): change the automatic page list.

## Rollout

Release it (`scout-aeo-v0.1.0`), then on each site: **Site Build → Your
features → AI search → Turn on**. Then add answers to the pages that bring in
business. Copy follows each client's claims rules (bar rules for law firms).

## What this feature needs from the theme

- Nothing. The questions box prints at the end of `the_content()` with its own small styles drawn from `currentColor`, `/llms.txt` and `robots.txt` are served by the plugin, and IndexNow runs on publish.
- Optional: `[scout_faq]` moves the answers to a chosen spot on a page, and the `scout_aeo_llms_txt` filter keeps a hand-written guide (the Scout Media theme uses it).

<!-- visitor-data:start -->
## Visitor data

This plugin keeps nothing about the people who visit the site and sets no cookies.

This plugin only changes robots.txt, serves /llms.txt and its IndexNow key file, and prints questions and answers the site's editors write. IndexNow pings send only the addresses of pages that were published or updated, never anything about visitors.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
