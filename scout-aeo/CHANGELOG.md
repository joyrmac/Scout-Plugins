# Scout AI Search changelog

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
