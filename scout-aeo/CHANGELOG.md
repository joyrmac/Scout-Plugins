# Scout AI Search changelog

## 0.2.0 (2026-10-13)

- **Instant updates (IndexNow).** When a public page is published or updated, its address goes to IndexNow, so Bing and Copilot pick up the change right away (ChatGPT search also draws on Bing). One non-blocking request at the end of the page load, from the live site only; staging never pings. Pages hidden from search or behind a password are skipped. The key is created once and served at `/{key}.txt`. On by default; switch it off under **AI search → Instant updates**.

## 0.1.10 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.9 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.8 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.7 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.1.6 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.

## 0.1.5 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 0.1.4 (2026-10-12)

- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 0.1.3 (2026-10-12)

- **Content-Signal in robots.txt.** The two switches (AI search, AI training)
  are now also written as a `Content-Signal: search=yes, ai-input=..., ai-train=...`
  line, the standard way to tell AI companies how pages may be used. It goes
  into every group, because a crawler only reads the group that names it. If
  a Content-Signal line is already there, it is left alone. Filter
  `scout_aeo_content_signal` to change or remove it.

## 0.1.2 (2026-10-12)

- **A new look for every settings screen.** The plugin's screens now share
  one Scout design: a clear page header with the section name, settings
  grouped into rounded boxes, pill buttons, and a short sign-off footer with
  how to reach us. On client sites the header reads "Site Build" instead of
  "Scout". Nothing about how the settings work changed.

## 0.1.1 (2026-10-12)

- /llms.txt shows titles and summaries as plain text. WordPress curls quotes
  into entities like `&#8220;`, which showed up raw.
- When the theme writes its own guide (the `scout_aeo_llms_txt` filter), the
  settings screen says so and hides the description, page and post fields
  that would not be used.

## 0.1.0 (2026-10-12)

First release.

- Loads the shared updater on `plugins_loaded`, inside a conditional, so it
  never collides with another Scout plugin's copy.

- robots.txt groups for AI search crawlers and AI training crawlers, each with
  its own switch, on the live site only.
- `/llms.txt` built from the site: summary, contact, pages, posts, questions.
- "Questions this page answers" box on every page and post, printed with
  matching `FAQPage` schema; `[scout_faq]` to place it.
- **AI search** settings screen in the site's Build section.
