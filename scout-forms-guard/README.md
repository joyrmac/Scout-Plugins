# Scout Forms Guard

In-house spam protection for Gravity Forms. No reCAPTCHA, no Akismet, no
third-party calls. Three layers, all invisible to real visitors:

1. **Honeypot** — a hidden field humans never see. Bots fill it; we reject those.
2. **Time trap** — rejects anything submitted faster than 3 seconds (a bot) or
   later than a day (a stale/replayed page).
3. **Signed token** — a hidden field signed with the site's secret key, so a
   forged or copied submission fails the check.
4. **What the message says:** an entry that links to another website or uses a known pitch ("search index", "backlink") is marked as spam. The visitor sees the normal confirmation, no email goes out, and the entry waits in the form's Spam folder.

## Hidden upload guard

A reported critical Gravity Forms flaw (CVE-2026-84434, reported 9.8) lets a
stranger upload a file that runs code through any **File Upload field whose
Visibility is Hidden**. Gravity Forms has no fix yet, so this plugin:

- **Refuses the file.** On a normal submit, files sent to a File Upload field
  set to Hidden or Administrative are dropped before Gravity Forms reads the
  entry (the rest of the entry still arrives). Drag-and-drop uploads to such a
  field get a 403. If a drag-and-drop upload names no field and the form has
  any hidden upload field, it is refused too.
- **Lets staff through.** Logged-in users who can edit Gravity Forms entries
  (`gravityforms_edit_entries`) are not blocked.
- **Counts it.** Each refusal adds one to the monthly blocked count and fires
  `scout_forms_guard_blocked`.
- **Tells you what to fix.** Site admins see a red notice naming each form and
  field. Open the form, click the field, and in its Advanced settings set
  Visibility to Visible, or delete the field. Scout Build shows the same thing
  as a "Hidden upload fields" row in Site health
  (`Scout_Forms_Guard::upload_check()`).

The list of hidden upload fields is cached for six hours and cleared whenever a
form is saved or deleted. Gravity Forms source was not available when this was
written; the assumptions about its internals are listed at the top of
`includes/class-scout-forms-guard-uploads.php` and are checked on staging.

Requires **Gravity Forms** (the one third-party plugin Scout keeps). Without it,
this plugin does nothing.

## Filters

- `scout_forms_guard_min_seconds` (default 3)
- `scout_forms_guard_max_seconds` (default 1 day)
- `scout_forms_guard_content_check` (default true), `scout_forms_guard_spam_phrases` (array)
- `scout_forms_guard_blocked`: action fired when a submission or a hidden
  upload is blocked (log it if you want).
- `scout_forms_guard_block_hidden_uploads` (default true): return false to stop
  refusing hidden uploads. Add it in a plugin or mu-plugin file, not on `init`,
  because the drag-and-drop check runs before `init`. The admin notice stays.

## Note on caching

The timestamp is signed, so a cached form stays valid for up to a day. The
honeypot and signature work regardless of caching.
