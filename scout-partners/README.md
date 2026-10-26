# Scout Partners

Partners and sponsors for a site that sells placements: Founding, National
and Regional partners (or whatever the owner calls their levels), each with a
logo, a link and the places on the site they appear. Change a partner once
and every placement follows. Built for Prepper Camp Nation's partner program
and kept general, so any event or nonprofit client with sponsors can use it.

## Where it lives

**Partners** has its own sidebar item with the Scout bolt (a plain award icon
when `SCOUT_HUB_BRAND` is false). Editors and administrators can manage
partners; only administrators change Settings.

- **Overview**: how many partners are on the site, by level, what ends in the
  next 30 days, and clicks to each partner this month and last.
- **All partners / Add partner**: name, logo (the featured image), and the
  **Partner details** box: level, website, one line, where they appear, and
  optional dates. Outside its dates a partner comes off the site by itself.
  The Order field sets the order within a level.
- **Settings**: the levels (highest first) and the places partners can
  appear, one per line, and, with Scout Events, partners on event pages.

## On the site

- `[scout_partners]` every partner on the site, grouped by level.
- `level="founding"`, `placement="home-page"`, `region="midwest"` narrow it.
- `layout="rail"` one row of logos (a header strip or sidebar);
  `layout="logos"` logos only; `title="Our partners"` adds a heading.
- With Scout Events and its regions, a partner can carry regions. Beside a
  region's events it shows that region's partners and the national ones (no
  region). Settings can add partners to every event page.

Links go through the site once (`admin-post.php?action=scout_partner&p=ID`),
which counts the click and sends the visitor to the partner's saved website,
never anywhere else. Links are marked `rel="sponsored"`. Search engines and
link checkers are not counted, and no visitor details are kept: only a number
per month, for two years.

## Styling

Class names: `.sp-partners`, `.sp-grid`, `.sp-rail`, `.sp-logos`, `.sp-title`,
`.sp-level`, `.sp-level-name`, `.sp-list`, `.sp-partner`, `.sp-link`,
`.sp-logo`, `.sp-name`, `.sp-line`. Custom properties:
`--scout-partners-line`, `--scout-partners-logo-bg`, `--scout-partners-radius`.

## Data

Post type `scout_partner` (not public), details in `_scout_partner`, monthly
clicks in `_scout_partner_clicks`, settings in the `scout_partners` option.
Deleting the plugin keeps partners.
