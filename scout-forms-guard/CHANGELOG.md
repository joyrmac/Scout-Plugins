# Scout Forms Guard — Changelog

## 0.3.1 (2026-10-30)

- **Released as 0.3.1, not 0.3.0.** Test copies of 0.3.0 went onto staging and one live site before release. A release with the same number would never replace them, so the first release is 0.3.1. Everything below ships in it.

- **Refuses files sent to hidden upload fields.** A reported critical Gravity Forms flaw (CVE-2026-84434, reported 9.8) lets a stranger upload a file that runs code through any File Upload field whose Visibility is Hidden. Gravity Forms has no fix yet. Scout Forms Guard now drops any file sent to a File Upload field set to Hidden or Administrative, on a normal submit and on drag-and-drop uploads (those get a 403). A visitor never sees those fields, so real visitors lose nothing. Logged-in staff who can edit entries are let through. Each refusal adds to the monthly blocked count. Turn it off with the `scout_forms_guard_block_hidden_uploads` filter.
- **Names the fields to fix.** Site admins see a red notice listing each form and field, with a link to the form and what to change (set Visibility to Visible, or delete the field). `Scout_Forms_Guard::upload_check()` gives Scout Build the same answer as a Site health row; `Scout_Forms_Guard::hidden_uploads()` returns the list.
- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.2.0 (2026-10-16)

- **Counts the spam it blocks.** Each blocked submission adds one to a monthly count (the last 13 months are kept; nothing about the sender is stored). Scout Build 0.6.2 shows it on the Dashboard, so the client can see the protection working. `Scout_Forms_Guard::blocked()` returns this month's count, `blocked( 'all' )` the total kept.

## 0.1.5 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 0.1.4 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.3 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.2 (2026-10-12)

- **Fix: a site crash when two Scout plugins load together.** Every Scout
  plugin carries the shared updater. On hosts that cache compiled PHP
  (OPcache, as on WP Engine), a copy could be declared the moment its file
  loaded, so a second plugin loading first crashed the site with "Cannot declare
  class Scout_Plugin_Updater". The class now sits inside a conditional, and the
  updater loads on `plugins_loaded`, after every plugin's own file.

## 0.1.1 — 2026-09-03

Updates now install themselves.

- The shared updater opts this plugin into WordPress's automatic updates;
  define `SCOUT_DISABLE_AUTO_UPDATES` as `true` in `wp-config.php` to opt a
  site out. Other plugins' auto-update decisions are untouched.

## 0.1.0 — 2026-06-18

Initial scaffold.

- Honeypot, time trap, and HMAC-signed token injected into every Gravity Form.
- Validation via `gform_validation`; generic retry message via
  `gform_validation_message`.
- Filters: `scout_forms_guard_min_seconds`, `scout_forms_guard_max_seconds`,
  `scout_forms_guard_blocked`.
