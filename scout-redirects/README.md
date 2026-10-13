# Scout Redirects

Forward old page addresses to new ones, see which redirects get used, and
catch the missing pages people ask for so each one can become a redirect in
one click.

## What it does

- **Redirect list.** Add, edit and delete permanent (301) and temporary (302)
  redirects from an old path to a new path or a full web address. Each one
  shows its hit count and when it was last used. Old addresses are matched
  without regard to capital letters or a closing slash, and a visitor's query
  string (campaign tags) is carried over when the new address has none.
- **Missing pages.** Requests that end on the missing-page screen are logged
  with path, visits, last seen and referrer. Crawlers and scripts, WordPress's
  own folders, `.php` and other file probes, favicons and asset requests are
  left out. The log is capped (500 by default, 50 to 2,000), dropping the
  oldest, least visited entries first. "Forward it" turns an entry into a
  redirect; "Ignore" and "Clear the list" tidy it.
- **Broken links on your pages.** A missing page whose referrer is the site
  itself means a link on that page is broken. Those are listed with an "Edit
  the page" link and reported in Tools, Site Health.
- **Pages that move.** When a published page's address changes (new slug or
  new parent), the old address and its child pages' old addresses forward to
  the new ones, redirects that pointed at the old address are updated so
  there is only ever one hop, and a notice offers Undo. Posts are left to
  WordPress, which already does this through `_wp_old_slug`.
- **CSV import and export.** Columns `from,to,type`; header row optional.
  Export adds `hits,last_used`.

## Where it runs

Redirects run on `parse_request` at priority 0, before WordPress looks the
page up, and only for GET and HEAD. They never apply to the dashboard,
`wp-login.php`, the REST API (`/wp-json/` and `?rest_route=`), `xmlrpc.php`,
`wp-cron.php`, `wp-content` or `wp-includes`, and those addresses cannot be
saved as an old address. A site with no redirects skips the lookup entirely.

## Storage

Two small custom tables, `{prefix}scout_redirects` and `{prefix}scout_404_log`,
each keyed by an MD5 of the normalized path with a unique index. A redirect is
looked up on every front-end request and its counter is written on every use;
in a single option every hit would rewrite and autoload the whole list, which
gets slow and racy once a rebuild imports a few hundred old addresses. The
table gives one indexed read per request (cached in the object cache) and a
one-row update per hit. An autoloaded count lets a site with no redirects skip
the query.

## Settings

Scout Build (or Site Build) → Redirects, or Settings → Redirects without a
Build section.

| Setting | Default |
|---|---|
| Forward a page automatically when its address changes | On |
| Keep a list of missing pages people ask for | On |
| Missing pages to keep | 500 |

## Filters

- `scout_redirects_target( $url, $row )`: change where a redirect goes, or
  return `''` to skip it.
- `scout_redirects_is_noise( $is_noise, $path )`: decide whether a missing
  address is left out of the log.

## Uninstall

Deleting the plugin removes the missing-page log, its options and notices. The redirects stay, so reinstalling brings them straight back. To remove them too, define `SCOUT_PURGE_ON_UNINSTALL` as `true` in wp-config.php before deleting.
Export the redirects first if they may be needed again.
