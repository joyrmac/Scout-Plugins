# Scout Accessibility

An honest accessibility statement page, a footer link to it, weekly checks
for the issues a computer can find, and an at-launch checklist for the ones
that need a person in a real browser.

**No overlay widgets, ever.** This plugin adds no toolbar, no script that
rewrites pages, and nothing that claims to make a site compliant. Overlays do
not fix the underlying code and often get in the way of people who use screen
readers. Accessibility is built into the theme and the content.

## Where

Build → Accessibility (Scout Build, Recon Build or Site Build, whichever the
site has). Without a Build section it sits under Settings.

## The statement

Saving the statement form creates a published page titled **Accessibility**
(slug `accessibility`) holding `[scout_accessibility_statement]`, or updates
the existing one. Words added around the shortcode are kept.

| Setting | Default | Notes |
|---|---|---|
| Business name | Scout Core business name, else the site title | Read only here |
| Email for barrier reports | Scout Core business email | |
| Phone | Scout Core business phone | |
| Reply within | 0 (left out) | Business days, the owner's own number |
| Other formats | Off | Adds "We can also send you any page in another format." Only turn on if the business will do it. |
| Standard | WCAG 2.2 AA | Fixed |
| Last reviewed | Today, on first save | Shown as month and year |

What the statement says: the business aims to meet WCAG 2.2 at level AA, how
the site is checked, that some parts may fall short (older pages, documents,
third-party tools), and how to report a barrier with the reply time. When
both at-launch checks are ticked it adds that contrast and keyboard use are
reviewed by hand. It never claims full compliance.

The email and phone links carry `data-scout-track="accessibility_contact"`
(label `email` or `phone`), so Scout Analytics reports them when it is active.

Theme styling: the statement inherits the theme's fonts and colors. The
buttons are outlined in `currentColor`. Override with `--scout-a11y-gap`,
`--scout-a11y-radius`, `--scout-a11y-border` and `--scout-a11y-muted`.
The stylesheet loads only on a page that shows the statement or when the
automatic footer link is on.

## Footer link

Three ways, use whichever fits the theme:

1. **Add it to a menu**: pick the footer menu and the page is added.
2. **Shortcode**: `[scout_accessibility_link]` in a footer widget or a
   Shortcode block in the footer template part.
3. **Print it automatically**: a small centered link after the theme footer
   (off by default).

## Weekly checks

A weekly WP-Cron event (`scout_accessibility_weekly`) reads published pages
and posts, rendered the way visitors get them, so shortcodes and form blocks
are included.

| Check | Fails when |
|---|---|
| Image descriptions | An `<img>` has no `alt` attribute at all (an empty `alt` is a deliberate decorative choice and passes), or a featured image has no alt text in the media library |
| Heading order | A heading skips a level going down (H2 to H4), or the content has its own H1 (the theme prints the title as the H1) |
| Form labels | An input, select or textarea has no `<label>`, `aria-label`, `aria-labelledby` or `title`. A placeholder alone does not count. Hidden fields are skipped. |
| Statement page | The page is missing, no link to it is found (home page, menus, widgets, footer template parts, or the automatic link), or it was not reviewed in the last year |

Filters: `scout_accessibility_post_types` (default `page`, `post`),
`scout_accessibility_max_posts` (default 300, most recently changed first).

## At launch

Color contrast and keyboard use need a real browser, so they are a checklist a
person ticks with the date and their name. Redo them after a redesign or a new
page template.

## Stored data

Options `scout_accessibility`, `scout_accessibility_results`,
`scout_accessibility_launch` and the weekly event. Uninstall removes them all,
and removes the Accessibility page only if nobody has edited it.
