# Scout Cards changelog

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
