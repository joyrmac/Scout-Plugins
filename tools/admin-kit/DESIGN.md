---
name: Scout dashboard
scope: ".sh (every Scout screen inside a client's WordPress dashboard)"
tokens: tools/admin-kit/tokens.json
css: tools/admin-kit/scout-admin.css
kit: 1.11.0
design-system-branch: Dashboard (project/tokens/dashboard.css in the Scout design system)
---

# DESIGN.md: the Scout dashboard

These are the design rules for every Scout screen inside a client's WordPress dashboard: Home, Inbox, the feature screens, and the Plus sections. Read this file before you build or restyle an admin screen. Read `docs/dashboard-map.md` for *where* a screen goes. This file covers *how it looks*.

This file is different from a theme's `DESIGN.md` (Site Standard §4). That one styles the public site and comes from the client's theme. This one styles the Scout dashboard and is the same on every site.

The dashboard is the **Dashboard branch** of the Scout design system: Scout Core (cerulean spark, data set, status colors, the bolt, the voice), re-skinned for WordPress admin. It follows the Recon branch and the approved dashboard canvas (Scout Build Dashboard, Nov 4): light, calm, roomy, hairline borders, 16px body text, 44px controls, 18px card corners.

## 1. One source for tokens

- `tools/admin-kit/tokens.json` holds every token, in the list format the Scout design system page reads.
- `php tools/admin-kit/build-tokens.php` writes the `.sh { … }` token block into `scout-admin.css` and copies the kit into every plugin.
- `php tools/tests/admin-tokens.php` (CI) checks three things. The CSS matches the JSON. Every variable the kit uses exists. The contrast pairs in §7 pass.
- `php tools/admin-kit/build-tokens.php --ds <file>` writes the design system's `tokens/dashboard.css`.
- Never type a hex into plugin CSS when a token exists. A plugin's own stylesheet uses `var(--sh-…)` inside `.sh`.

## 2. How a screen should feel

These come from Joy's dashboard brief (2026-11-04) and are written for ADHD and autistic brains. They're good for everyone.

1. **One job per screen.** The title says what you do here.
2. **Summary before detail.** What needs you comes first. Details sit below it or behind one click.
3. **One primary button per screen.** Everything else is a secondary or quiet button.
4. **Status in words plus a shape.** For example "On" with a dot, or "Needs a look" with an amber dot. Never color alone.
5. **At most two or three across.** Card grids use `minmax(var(--sh-col-min), 1fr)`. At 1180px that allows three columns at most. Four across is never OK.
6. **Chips, not dot lists.** Lists of short things are chips. Never write "A · B · C".
7. **Calm.** No looping animation, no bounce. Hover fades only, and Larger text or Less motion (Users → Profile) turns motion off.
8. **Plain words.** Sentence case. No em dashes. No jargon without a plain explanation beside it. The full rule is section 2a.

## 2a. Words on every screen (Sean's voice, Joy 2026-11-05)

Straight, short and plain. Say it once. Someone who has never run a website should get it on the first read. This is the voice of the minimal Scout cards: say what it is, then stop.

0. **A real word stays.** When the common word is already plain, keep it: "Redirects", "Analytics" (Joy, 2026-11-05). Rename only jargon or made-up names.
1. **Say what it is or what to do.** "Hours", not "Manage your business hours settings".
2. **One idea per line.** Help text is one sentence, about 20 words at most. Anything longer goes behind a "More" fold.
3. **Common words first.** When a real term is needed (SEO, schema, robots.txt), put a few plain words beside it once: "robots.txt (tells search engines which pages to skip)".
4. **Buttons say the action.** "Save", "Add a person", "Send it". Never "Submit", "Proceed" or "Click here".
5. **Status in one to three words.** "On", "Off", "Needs a look", "Waiting for your OK".
6. **No cute names, pep or filler.** No puns, no exclamation points, no "Let's", "Oops", "Awesome", "simply", "easily", "just", "please note", "in order to".
7. **Same thing, same word.** The menu name, the screen title and the button that opens it use the same words.
8. **Calm and certain.** Don't hedge with "might" or "you may want to" when we know. Contractions are fine.
9. **Scout-branded.** Never name Claude or a partner agency. On white-label sites say "your web team", not Scout.
10. **"&" in names, "and" in sentences.** Menu names, screen titles, box titles and field labels that pair two nouns use "&": "Cards & links", "Visits & cookies", "Privacy & terms". Buttons that do two things keep "and" ("Approve and welcome"). Text visitors see does not change.
11. **Real characters when they help.** Curly quotes and apostrophes (’ “ ”), the ellipsis (…), × for sizes, › and → for "go here". Ligatures are on (kit CSS), with the fancier joined letters only in headings and only where the font has them. Never an em dash.

Before and after: "Get found" became "Search". "Your website" became "Site features". "Keep tabs on how your site is doing across a range of important checks" becomes "Is the site working? Green means yes."

### Menu group names (kit 1.11.0)

Home, To do, Reports, Business, People, Site features, Search & analytics, Legal pages, Site status, Scout tools. Labels only: the group keys (`inbox`, `business`, `website`, `search`, `policies`, `health`) never change.

## 3. Color

Light only. WordPress's own screen parts have no dark mode, so a dark Scout screen would sit inside a light frame.

### Surfaces and text

| Token | Value | Use |
|---|---|---|
| `--sh-bg` | #F5F5F7 | Canvas, when a screen paints one. WordPress's #F0F0F1 shows around it. |
| `--sh-surface` | #FFFFFF | Boxes, cards, tables, inputs |
| `--sh-surface-2` | #EDEDF0 | Insets, hover fills, chips, meter track |
| `--sh-text` | #1D1D1F | Headings and body |
| `--sh-text-2` | #515154 | Ledes, summaries, chip labels |
| `--sh-text-3` | #6E6E73 | Notes, labels, table heads. Darker than the brand #86868B so small text passes AA. **Never on `--sh-surface-2`.** |
| `--sh-border` | rgba(0,0,0,.08) | Hairlines, box borders |
| `--sh-border-strong` | #D2D2D7 | Input borders |

### Cerulean has three jobs

Pick the token by what sits on it.

| Token | Value | Job |
|---|---|---|
| `--sh-accent` | #0E8FE6 | **No text on it.** Bolt, dots, meter, active underline, checkbox fill, focus border |
| `--sh-accent-fill` | #0A78C2 | **White text on it.** Primary button, active nav group, active big tab, pager current (4.69:1) |
| `--sh-accent-fill-hover` | #08639F | Hover and pressed state of the fill |
| `--sh-accent-text` | #0A6FB4 | Cerulean as text: links, eyebrows, quiet buttons |
| `--sh-accent-line` | rgba(14,143,230,.5) | Cerulean borders: outline buttons, a card that's on, hover borders |
| `--sh-glow` | rgba(14,143,230,.14) | Focus ring, update tag, quiet hover |
| `--sh-on-accent` | #FFFFFF | Text on the fill |

`--sh-accent-ink` is the old name for the hover fill and stays for older copies. Don't use it in new code.

**Rule (checked in CI):** `color: #fff` or `var(--sh-on-accent)` never sits on `background: var(--sh-accent)`. White on #0E8FE6 is 3.45:1 and fails.

### Status: every status has three tokens

| Status | Text | Shape (dot, icon) | Fill behind text |
|---|---|---|---|
| Good (On, Done, Up to date) | `--sh-good` #0A6E50 | `--sh-good-mark` #0E9E73 | `--sh-good-tint` |
| Needs a look | `--sh-warn` #8A5A00 | `--sh-warn-mark` #FBB024 | `--sh-warn-tint` |
| Broken or risky | `--sh-bad` #C23A26 | `--sh-bad-mark` #F2553D | `--sh-bad-tint` |

Text uses the text token, and CI checks it passes 4.5:1 on its own tint. A dot uses the mark token. Never put text on a mark color: white on amber fails, and so does amber text on white.

### Feature kinds

| Kind | Token | Mark |
|---|---|---|
| Core | `--sh-kind-core` (cerulean) | The bolt |
| Bonus | `--sh-kind-bonus` (green) | A diamond |
| Plus | `--sh-kind-plus` #7B6CF0 | A plus sign, and the "+ Plus" tag in the sidebar |
| Recon | `--sh-kind-recon` (amber) | A bar chart |

### Data

`--sh-data-1` to `--sh-data-5` are cerulean, teal, coral, amber and violet. Use them in that order, for charts and categories only, never as decoration.

## 4. Type

- **Font: the WordPress admin system stack** (`--sh-font`), with `--sh-mono` for labels and numbers.
  - We don't load Hanken Grotesk in the dashboard. It would add a font download to every admin page on every client site, and the screen would clash with WordPress's own menus.
  - Hanken and Geist Mono stay the brand fonts for the public site, Recon and mockups. Canvas mockups use them, and that difference is on purpose.
- Headings are 600, never heavier, with slight negative tracking. Sentence case.
- Headings, buttons and labels use `text-wrap: balance`. Paragraphs use `text-wrap: pretty`.

| Style | Size token | Size | Weight | Use |
|---|---|---|---|---|
| Page title | `--sh-fs-page` | 34px (26px on phones) | 600, −0.03em | The one h1 |
| Section title | `--sh-fs-section` | 24px | 600, −0.02em | Sections inside a screen |
| Box title | `--sh-fs-box` | 20px | 600, −0.02em | A box's title |
| Card title | `--sh-fs-card` | 17px | 600 | A card in a grid |
| Lede | `--sh-fs-lede` | 17px | 400, `--sh-text-2` | One sentence under the title |
| Body | `--sh-fs-base` | 16px (18px with Larger text) | 400, 1.5 | Everything else |
| Note | `--sh-fs-note` | 14px | 400, `--sh-text-3` | Help text, footers |
| Eyebrow | `--sh-fs-label` | 12px mono, uppercase, 1.4px | 500, `--sh-accent-text` | Above a title |
| Label | `--sh-fs-label` | 12px mono, uppercase, 1.2px | 500, `--sh-text-3` | Table heads, small labels |
| Number | `--sh-fs-number` | 34px mono | 600, tabular | Stats |

## 5. Space, shape, depth, motion

- **Space:** 4px steps, `--sh-space-1` (4px) to `--sh-space-10` (40px). Use `gap` on flex and grid, not margins between siblings.
- **Radius:**
  - `--sh-r-chip` 8px: chips, code
  - `--sh-r-control` 10px: inputs, notices
  - `--sh-r-card` 18px: boxes, cards, tables, big tabs (Scout Core cards)
  - `--sh-r-pill` 980px: buttons, status pills, the nav bar
- **Shadow:** none at rest.
  - `--sh-shadow-hover` on a hovered card
  - `--sh-shadow-active` on the active big tab
  - `--sh-shadow-nav` on the active nav group
- **Motion:** `--sh-dur` (.2s) and `--sh-ease` (Recon's quick-start, soft-landing curve), on color, border and shadow only. Everything turns off under `prefers-reduced-motion` and Less motion.
- **Layout:**
  - `--sh-max` 1180px is the widest a screen gets.
  - `--sh-col-min` 340px is the smallest grid column.
  - `--sh-tap` 44px is the smallest button, input or menu link.
  - `--sh-pad` 24px is the inside of a box or card.

## 6. Components (classes in `scout-admin.css`)

| Part | Class | Notes |
|---|---|---|
| Screen wrapper | `.sh` | Holds the tokens. Every Scout screen prints it. `Scout_Admin_UI::open()` and `close()` do it for you. |
| Header bar | `.sh-bar`, `.sh-crumb`, `.sh-find` | One white bar, edge to edge: breadcrumb (Scout › section › screen), Find anything (Ctrl or Cmd + K), one button. The button is Request a change unless the screen sets its own with `bar_action()`. |
| Page column | `.sh-page` | Pinned to the left, beside the WordPress menu, never centered in the window. The section list and the content line up with the menu on every screen width (kit 1.11.0). |
| Section list | `.sh-local` | The section's screens on the left, current one with a cerulean inset bar. Shows when the section has more than one screen or a "Looking for" link. On WordPress's own list screens it becomes a row of pills (`.is-row`). The WordPress sidebar is the only menu: no tab rows across the top. |
| Looking for | `.sh-looking` | One quiet line at the bottom of the section list that opens a screen in its home group ("Looking for the cookie notice? It's in Search & analytics ›"). At most two per group, set in `Scout_Admin_UI::LOOKING`. |
| Page head | `.sh-pagehead`, `h1`, `.sh-lede`, `.sh-eyebrow` | The screen name as the h1, one sentence under it. Home sets a sentence headline with `page_head()` ("Good morning. Two things need you today.") in a 1040px column. |
| Find anything | `.sh-finder` | Ctrl or Cmd + K: "Do it now" (Today's items, the `scout_admin_finder_items` filter) and "Go to" (every screen this person can open). Arrow keys, Enter, Esc. |
| Big tabs | `.sh-tabs` | Two to four choices, each with a live hint. One column on phones. |
| Box | `.sh-box` | One topic. Title `h2.sh-title`, then a sentence, then the fields. |
| Card grid | `.sh-grid`, `.sh-grid-2`, `.sh-card` | Cards line up their buttons at the bottom (`.sh-actions`). `.is-on` gets the cerulean border. Side by side only while the main column is wide: `.sh-grid-2` stacks below 860px of column, `.sh-grid-3` below 600px (container queries on `.sh-main`, kit 1.10.0). A plugin's own two-column layout uses `@container shmain (...)`, never a window-width media query. |
| Status line | `Scout_Admin_UI::status( $state, $headline, $detail, $go )`, `.sh-status.is-warn / .is-bad / .is-off` | The first thing on a screen: a dot, a few bold words, the detail, at most one button. Replaces a row of three status cards (kit 1.10.0). |
| Rows | `.sh-rows > .sh-row`, `.sh-row-name`, `.sh-row-sub`, `.sh-row-facts`, `.sh-row-go` | A list of things, one row each: the name and one line, then facts, then one main button and a More menu. Stacks below 640px of column. The Features screen is the model. |
| More options | `Scout_Admin_UI::fold_open( $title )` and `fold_close()`, `.sh-fold` | A closed box at the bottom for what people rarely change: settings, import, export. |
| Status pill | `.sh-pill.is-on / .is-done / .is-progress / .is-received` | Word plus dot. |
| Tag | `.sh-tag.is-current / .is-update / .is-held` | Small facts on a card. |
| Health check | `.sh-check.is-warn / .is-bad`, `.sh-dot`, `.sh-value` | Dot plus a word value. |
| Meter | `.sh-meter > span` | Progress, cerulean on the inset. |
| Steps | `.sh-steps > .sh-step` | Numbered with mono numerals. `.is-done` turns green. |
| Stats | `.sh-stats > .sh-stat > .sh-num` | Mono numbers, tabular. |
| Buttons | `.button-primary`, `.button`, `.button.sh-quiet`, `.sh-danger` | Pills. Primary is the fill. Secondary is outlined. Quiet is text only. Danger is `--sh-bad` text, never a red fill. |
| Forms | WordPress's `.form-table`, inputs and notices, restyled | Labels left, help text under the field in `.description`. |
| Tables | `.widefat`, `.sh-table` | Mono uppercase heads, hairline rows, no zebra stripes. |
| Sidebar Plus tag | `.sh-plus-tag` | Printed by the kit on Plus sections. Uses the purple on WordPress's dark sidebar. |

## 7. Accessibility (checked in CI where it can be)

- **Contrast, 4.5:1 for text.** CI checks:
  - White on the fill and on the hover fill
  - All three text tokens on white
  - `--sh-text-2` on `--sh-surface-2`
  - `--sh-text-3` on the canvas
  - Every status text color on white
- Focus is always visible: a 2px `--sh-accent-text` outline, or the `--sh-glow` ring on inputs.
- Buttons, inputs and menu links are at least `--sh-tap` (44px) tall, and buttons are 48px with Larger text.
- Larger text (18px everywhere) and Less motion live on each person's profile (`scout_ui_comfort`).
- Never show state by color alone. Every status has a word.

## 8. Don't

- Don't open a screen with a row of status cards. Use the status line.
- Don't put a primary button on every row of a list. One main button per row, outlined; the screen's one primary is the action people came for.
- Don't put the settings above the thing people use. Settings go in More options unless the screen is only settings.

- Don't use a hex where a token exists, or invent a new color. Add a token to `tokens.json` instead.
- Don't put white text on `--sh-accent` or on any mark color.
- Don't put four or more items across.
- Don't add a dark mode.
- Don't load web fonts in the dashboard.
- Don't add icons beyond the bolt, the kind marks and WordPress's dashicons in the sidebar.
- Don't add looping animation, bounce or autoplay.
- Don't edit a plugin's copy of the kit. Edit `tools/admin-kit/`, run `build-tokens.php`, and bump the kit `VERSION`.

## 9. Changing a token

1. Edit `tools/admin-kit/tokens.json`.
2. Run `php tools/admin-kit/build-tokens.php`. It rewrites the block and copies the kit into every plugin.
3. Run `php tools/tests/admin-tokens.php`.
4. Bump `Scout_Admin_UI::VERSION` so browsers fetch the new CSS, and note it in the Scout Build changelog.
5. Regenerate the design system's branch file (`--ds`) and publish it, so the Scout design system matches the code.
