# Scout Law Firm Tools

The pieces a law firm's website needs to stay careful. Core for every law
firm Scout builds for (catalog tier `build`, `law: true`). Screens file under
**Scout → Policies** (see `../docs/dashboard-map.md`).

## Bar rules differ by state, and the firm signs off

Every state bar has its own advertising rules, and every firm has its own way
it wants to be seen. This plugin gives starting wording for North Carolina,
Virginia, Florida and Washington, DC. It does not decide whether any wording
complies. **The firm's attorneys review and approve the final wording**, and
the approver's name, the date and the exact words are kept as the record.

## Disclaimers (0.1.0)

| Line | Shows by default | What it says |
|---|---|---|
| Not legal advice | Footer | The website is general information, and contacting the firm does not create an attorney-client relationship. |
| Before someone sends a form | Under forms | Don't send confidential details through the website. |
| Attorney advertising | Footer | A short label. The firm decides whether it shows. |
| Prior results | Next to case results | Prior results do not guarantee a similar outcome, in the state's starting wording. Replaces the wording on the Case results screen while this plugin is on. |
| Reviews and testimonials | Under reviews | Reviews reflect individual clients' experiences. Replaces the Review settings disclaimer on the site while this plugin is on. |

- A line always prints something: a blank box uses the starting wording.
- An approval belongs to one exact wording. Edited words read "Changed since
  approval" until the firm approves again. Extra spaces don't count as a change.
- Lines that show somewhere and wait on the firm add a row to the Scout Inbox
  (`scout_hub_inbox`).
- Every approval is kept in `scout_law_approval_log`, which stays when the
  plugin is deleted.

### For themes

```php
add_theme_support( 'scout-law-footer' ); // the theme places the footer lines
scout_law_disclaimers( 'footer' );        // where the theme wants them
```

Shortcodes: `[scout_disclaimers place="footer"]` (every line for a place) and
`[scout_disclaimer line="relationship"]` (one line).

### Filters

| Filter | What it changes |
|---|---|
| `scout_law_starting_wording` | The starting wording for a line and state. |
| `scout_law_disclaimer_text` | The wording a line prints (an empty return falls back to the starting wording). |
| `scout_law_disclaimer_approved` (action) | Runs when the firm's approval is recorded. |

## Approval before publishing (0.2.0)

- **Scout → Policies → Approvals** picks what needs approval. Blog posts and
  team bios start on; Pages is off until the site is built, because it also
  holds pages Scout publishes. Case results keep their own approval.
- Publishing without an approval saves as **Pending review** (block editor,
  classic editor, quick edit and code all go through the same check).
- The **Firm approval** panel (block editor sidebar and pre-publish check) or
  box (classic editor) takes the attorney's name and a tick. The name, date and
  exact version (a revision) are kept in `scout_law_content_log`, which stays
  when the plugin is deleted.
- A live item edited after approval stays live and reads **Edited since
  approval** until the firm approves again. Both queues add Inbox rows.
- Filter `scout_law_approval_types` adjusts the list of post types. Action
  `scout_law_content_approved` runs on each approval.

## "Reviewed by" stamps (0.3.0)

- An attorney on the firm's Team (Team profile with **Attorney** ticked) signs a
  page as reviewed: the **Reviewed by** panel in the block editor, the classic
  editor box, or **Scout → Policies → Page reviews**. The name and the date are
  saved together, only when "Reviewed today" is ticked.
- The page shows "Reviewed by *attorney*, *role*. Last reviewed *date*." under
  its words, linked to the profile, and its schema gains `reviewedBy` (the
  Person node from Scout Team) and `lastReviewed`. Turn the line off in More
  options; a theme places it itself with `add_theme_support( 'scout-law-reviewed' )`
  and `scout_law_reviewed()`.
- Pages not reviewed in the review period (6 months by default) are listed on
  Page reviews, counted in the Inbox, and put on Home's Today list. The privacy
  page is left out (it has its own yearly review).
- Scout team logins never appear: only Team profiles can sign.
- Filter `scout_law_review_types`; action `scout_law_page_reviewed`. Every
  review is kept in `scout_law_review_log`.

## Form entries that delete themselves (0.4.0)

- Each night, Gravity Forms entries older than 90 days are deleted from the
  website with their uploaded files, in every status (active, spam and trash).
  **Scout → Policies → Form entries** shows what happens to each form. More
  options sets 30, 60, 90, 180 or 365 days, turns deleting off, or keeps every
  entry from chosen forms.
- The first delete waits 14 days after the plugin first runs on a site. Home's
  Today list shows the start date until then.
- Always kept: starred entries, forms that email no one (the entry is the only
  copy), and forms with their own Gravity Forms retention setting.
- At most 500 entries per form per night. The deletion record keeps the date
  and the count only.
- Only the website's copy goes. Emails already sent, and the firm's case and
  intake records, are untouched.
- `scout_law_entry_days()` returns the days kept (0 when off), for the privacy
  policy page.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Form entries visitors send through the site's Gravity Forms, and the files uploaded with them (Gravity Forms stores these; this plugin deletes old ones) (only while the automatic delete setting is on (it is on unless the firm turns it off)) | This website's database and uploads folder, where Gravity Forms keeps them | Each night, entries older than the firm's limit are deleted: 90 days unless the firm picks 30, 60, 180 or 365. This includes entries in the spam and trash folders. Starred entries are kept, and so are entries from forms the firm chose to keep, forms that send no email, and forms that use Gravity Forms' own delete setting. The first delete waits 14 days after the plugin first runs, and each night deletes up to 500 entries per form. | Nobody |

Scout Law collects nothing from visitors itself. Its nightly log keeps only how many entries were deleted, never names or messages.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
