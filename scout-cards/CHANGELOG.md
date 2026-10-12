# Scout Cards changelog

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
