# Scout AI Search changelog

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
