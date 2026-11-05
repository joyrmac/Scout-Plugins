# Scout theme kit

Files every Scout theme copies in, so every site has the same back end.

| File | Copy to | What it does |
|---|---|---|
| `scout-requires-build.php` | `inc/scout-requires-build.php`, required from `functions.php` | When Scout Build is missing, admins get one "Install Scout Build" button. It installs the newest release from the release feed (or turns on a copy already there). Scout Build updates itself after that. Also carries a stand-in `scout_core_owns()` that answers false while Scout Core is off, so a theme's rule 6 fallback (`if ( ! scout_core_owns( 'hours' ) )`) never fatals. |
| `../admin-kit/class-scout-admin-ui.php` + `scout-admin.css` | `inc/` and `assets/css/` | The Scout look for the theme's own screen, which sits at Scout → Website → Theme. |
| `style-guide/scout-style-guide.php` | `inc/scout-style-guide.php`, required from `functions.php` after the Build file | The `/style-guide/` page: makes the page on activation, keeps it noindex, and prints the sections (brief, color, type, space and shape, motion, buttons and links, Scout's parts, logo). Also the `[scout_style_guide]` shortcode and `scout_style_guide()`. |
| `style-guide/page-style-guide.php` | theme root, classic themes only | The page template: the theme's header, the sections, the theme's footer. |
| `style-guide/style-guide.html` | `templates/page-style-guide.html`, block themes only | The same page as a block template: header part, the shortcode, footer part. |

Change a file here first, then copy it into each theme at that theme's next
update. Tested 2026-10-13 in WordPress 6.5 (Playground): newest version picked
from the feed, installed, activated, notice gone.

## The style guide page

Decided 2026-11-05 (Client Design Playbook, decision 4): every Scout theme
ships `/style-guide/`, noindex, built on the theme's own stylesheet and the
real Scout plugins. The client approves the system before the homepage, and
the page can never drift from the site because nothing on it is drawn twice.

What the page prints, in order:

1. **The brief** in one screen: the prose under a `## Brief` heading in
   DESIGN.md (register, signature element, the "no" list). `###` lines become
   subheads, `-` lines a list. No heading, no brief: the page says so.
2. **Color**: every token from `scout_design()` as a swatch with its name,
   value, `--scout-*` variable and role, plus the contrast of each text color
   that sits on it. The rule 10 pairs from `scout_design_issues()` sit at the
   top with pass or fail.
3. **Type**: the three fonts and the scale set in real sentences, in the
   theme's own `h1` to `small`.
4. **Space and shape**: the spacing steps, each radius token as a shape, the
   hairline.
5. **Motion**: a card that lifts on hover, and the reduced-motion state the
   page switches to on a device with that setting.
6. **Buttons and links**: a live row in the theme's own styles, then rest,
   hover, focus and pressed held still.
7. **Scout's parts**, each printed by its plugin and skipped with a plain line
   when the plugin is off: the Call buttons bar in a phone-width frame, the
   Reviews slot (home set), the Hours line, one People card, one Case result on
   law sites, the AI search questions box, the first Gravity Forms form, the
   accessibility statement.
8. **Logo** and mark from DESIGN.md on the page, on ink and on the accent.
9. The theme's footer, the real one.

The page is styled by the theme's stylesheet plus the `--scout-*` variables.
Scout Core prints those variables in the head before the theme's CSS, so a
theme that sets its own wins. The include adds layout only; the swatches carry
the DESIGN.md values because showing them is the job.

Noindex: with Scout Core on, the page carries Core's own noindex flag
(`_scout_seo_noindex`), so Core's robots control and sitemap handle it. Without
Core the include filters `wp_robots` itself. The page also stays out of site
search and the core sitemap either way.

Install in a theme:

1. Copy `style-guide/scout-style-guide.php` to `inc/` and add
   `require get_template_directory() . '/inc/scout-style-guide.php';` to
   `functions.php`, after the Build require.
2. Classic theme: copy `style-guide/page-style-guide.php` to the theme root.
   Block theme: copy `style-guide/style-guide.html` to
   `templates/page-style-guide.html`.
3. Activate the theme, or open any admin screen once: the page appears at
   `/style-guide/`. Delete it on purpose and it stays gone.

`tools/theme-standard-check.py` compares all three copies with the kit (rule
25) and warns when a theme has no style guide yet.
