# Scout Law Firm Tools changelog

## 0.2.0 (2026-11-04)

- **Approval before publishing (Scout → Policies → Approvals).** Blog posts and team bios need the firm's approval before they go live, and the firm can add pages or other content. Publishing without an approval saves the item as Pending review, from the block editor, the classic editor, quick edit or code.
- **Firm approval in the editor.** A Firm approval panel in the block editor's sidebar and in the pre-publish check, and a box in the classic editor: the attorney's name and "The firm approved this version." The name, the date and the exact saved version (a revision) go in the approval record.
- **Edits after approval stay live.** A published item changed after its approval keeps showing, reads "Edited since approval" in its list column and on the Approvals screen, and adds a row to the Inbox until the firm records a new approval.
- **Inbox rows:** Waiting for the firm's approval, and Edited since approval.
- Not yet clicked through in a live block editor; check the publish flow on a staging site before merging.
- Test: `php tools/tests/law-approvals.php` (10 checks, in the Lint workflow).

## 0.1.0 (2026-11-04)

- **Disclaimers (Scout → Policies → Disclaimers).** The firm's required lines in one place: Not legal advice, Before someone sends a form, Attorney advertising, Prior results, and Reviews and testimonials. Starting wording for North Carolina, Virginia, Florida and Washington, DC, which the firm edits. A line always prints something: a blank box uses the starting wording.
- **Shows everywhere it's set to.** The footer of every page (a theme can place the lines itself with `add_theme_support( 'scout-law-footer' )` and `scout_law_disclaimers( 'footer' )`), under every Gravity Form, next to every case result (Scout Case Results) and under the reviews (Scout Reviews). Shortcodes: `[scout_disclaimers place="footer"]` and `[scout_disclaimer line="relationship"]`.
- **The firm approves the exact wording.** Tick the lines the firm approved and add the attorney's name. The name, date and wording are kept together in the approval record. Changing the words later marks the line "Changed since approval" until the firm approves again. Lines waiting on the firm add a row to the Scout Inbox.
- **The approval record stays when the plugin is deleted.** It is the firm's advertising record; only the settings are removed.
- Test: `php tools/tests/law-disclaimers.php` (18 checks, in the Lint workflow).
