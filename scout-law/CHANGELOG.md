# Scout Law Firm Tools changelog

## 0.4.1 (2026-11-05)

- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.4.0 (2026-11-04)

- **Form entries delete themselves (Scout → Policies → Form entries).** Each night, Gravity Forms entries older than 90 days are deleted from the website, with any files uploaded with them, in every status (active, spam and trash). The firm can pick 30, 60, 90, 180 or 365 days, turn deleting off, or keep every entry from chosen forms. On by default on law-firm sites (Joy, 2026-11-04).
- **Nothing goes the night the update lands.** The first delete waits 14 days after the plugin first runs on a site. Until then the screen and Home's Today list say the start date, so anyone who needs an old entry can save it or star it.
- **What is always kept:** starred entries, every entry from a form that emails no one (its entry is the only copy), and forms with their own Gravity Forms retention setting, which Gravity Forms handles.
- A night deletes at most 500 entries per form, so a large backlog spreads over a few nights. Each night that deletes something adds a line to the deletion record: the date and the count, never names.
- `scout_law_entry_days()` returns the days entries are kept, or 0 when deleting is off, for the privacy policy page to read.
- **Works with an older admin kit.** Policies arrived in admin kit 1.8.0. When a plugin with an older kit loads first (a partial rollout, or after "Go back a version"), Approvals, Page reviews and Form entries file under Search instead, so the screens still open with no warnings. Test: `tools/tests/law-kit-fallback.php`.
- Fix: uninstalling now removes the "Reviewed by" settings too. The review record stays, like the approval record.

## 0.3.0 (2026-11-04)

- **"Reviewed by" stamps.** An attorney on the firm's Team (a Team profile with "Attorney" ticked) signs a page as reviewed, from the page's Reviewed by panel in the block editor, the classic editor box, or Scout → Policies → Page reviews. The page then shows "Reviewed by <attorney>, <role>. Last reviewed <date>." under its words, linked to the attorney's profile, and its schema gains `reviewedBy` (the attorney's Person node from Scout Team) and `lastReviewed`. The name and the date are always saved together. Reviewing a page does not change its words, so it needs no new approval.
- **Pages due for a review.** Pages no attorney has reviewed in the review period (6 months by default; 3 or 12 in More options) are listed on Page reviews with a "Reviewed today" button each, counted in the Inbox, and put on Home's Today list (`scout_hub_today`) with about five minutes each. The privacy page is left out; it gets its own yearly review under Policies. Pages are the default; posts and other kinds can be added.
- **Fix: saving Disclaimers or Approvals returned to a "not allowed" page** on sites with the Scout menu. While a form saves, WordPress builds no menu, so the plugin thought it lived under Settings and sent people to `options-general.php?page=…`, which WordPress refuses for a screen in the Scout menu. Every Scout Law screen now links to `admin.php?page=…`, which opens wherever its menu is.
- Themes place the line themselves with `add_theme_support( 'scout-law-reviewed' )` and `scout_law_reviewed()`. Filter `scout_law_review_types`; action `scout_law_page_reviewed`. Every review is kept in `scout_law_review_log`.

## 0.2.1 (2026-11-04)

- **Fix: the approval record points at the version the attorney approved.** In the block editor, when the words changed in the same save as the approval, the record named the revision before it (the words the firm had not seen). The record now finds the revision by its exact words, after WordPress has saved it. Found testing 0.2.0 in a live block editor.
- **Fix: a held publish says so.** Publishing without an approval in the block editor showed WordPress's "Draft saved". It now says "Saved as Pending review, not published" and points at the Firm approval panel.

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
