# Scout Law Firm Tools changelog

## 0.1.0 (2026-11-04)

- **Disclaimers (Scout → Policies → Disclaimers).** The firm's required lines in one place: Not legal advice, Before someone sends a form, Attorney advertising, Prior results, and Reviews and testimonials. Starting wording for North Carolina, Virginia, Florida and Washington, DC, which the firm edits. A line always prints something: a blank box uses the starting wording.
- **Shows everywhere it's set to.** The footer of every page (a theme can place the lines itself with `add_theme_support( 'scout-law-footer' )` and `scout_law_disclaimers( 'footer' )`), under every Gravity Form, next to every case result (Scout Case Results) and under the reviews (Scout Reviews). Shortcodes: `[scout_disclaimers place="footer"]` and `[scout_disclaimer line="relationship"]`.
- **The firm approves the exact wording.** Tick the lines the firm approved and add the attorney's name. The name, date and wording are kept together in the approval record. Changing the words later marks the line "Changed since approval" until the firm approves again. Lines waiting on the firm add a row to the Scout Inbox.
- **The approval record stays when the plugin is deleted.** It is the firm's advertising record; only the settings are removed.
- Test: `php tools/tests/law-disclaimers.php` (18 checks, in the Lint workflow).
