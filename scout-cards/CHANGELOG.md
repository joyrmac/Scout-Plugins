# Scout Cards changelog

## 1.0.12 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 1.0.11 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 1.0.10 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 1.0.9 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 1.0.8 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.

## 1.0.7 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 1.0.6 (2026-10-12)

- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 1.0.5 (2026-10-12)

- **Stands down when the theme already makes cards.** On the Scout Media theme
  (which has its own cards and links pages), Scout Cards now does nothing: no
  routes, no menu, no second "Site Build" section, and nothing that could take
  over the theme's /card/ and /links/. The Plugins screen says so. Before, it
  added its own section and its own Cards & Links screen on top of the
  theme's. Filter `scout_cards_theme_has_cards` to decide.

## 1.0.4 (2026-10-12)

- **A new look for every settings screen.** The plugin's screens now share
  one Scout design: a clear page header with the section name, settings
  grouped into rounded boxes, pill buttons, and a short sign-off footer with
  how to reach us. On client sites the header reads "Site Build" instead of
  "Scout". Nothing about how the settings work changed.

## 1.0.3 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 1.0.2 (2026-10-12)

- Nests Cards & Links inside the site's existing Build menu (Scout Build, Recon
  Build, Scout Core's Scout menu, or the Scout Build hub's section) instead of
  adding a second Site Build menu. Sites with none of those are unchanged.

## 1.0.1 (2026-10-01)

- Richer spotlight design, tinted with the accent color. With a heading or note
  it shows as a signed panel: "A note from <first name>", a bold lead (a blank
  line in the note makes the first paragraph the lead), and the person's photo,
  name, and title. The link row gets an accent glow, the domain in small caps
  for outside links, and an arrow that slides on hover.

## 1.0.0 (2026-10-01)

First release, ported from the cards built into the Scout Media and Scout Recon
themes so every client site can have them.

- Main card, per-person cards, Save contact (vCard 3.0 with a JPEG photo), share
  mode with a big QR code and share buttons, a web app manifest for the home
  screen, and the `/links/` link-in-bio page with the newest post first.
- Reads the business identity from Scout Core; every field can be overridden.
- Scout -> Cards & Links screen (or a top-level Site Build section without Scout
  Core): look (accent color, light or dark, optional font), business details, main
  card, up to six people with Media Library photos, up to ten link rows, and the
  card and links addresses.
- Card pages print their own title, canonical, and `noindex, follow`, and switch off
  Scout Core's head tags there. Unknown people return a real 404.
- Optional spotlight under each card: a heading, a short note, and one link row
  (a product, a booking page, or a second business). Blank hides it.
- Warns when a WordPress Page already uses the card or links address.
- Self-updating from the Scout Plugins releases.
