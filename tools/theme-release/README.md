# Theme releases: client themes that update themselves

Scout plugins update from this public repo. A client theme lives in the
client's own private repo, so it updates the same way with a read-only key
on the site. Scout Build 0.7.0 or later does the checking and installing.

## Once per theme (in the client repo)

1. Add three lines to the theme's `style.css` header (or to the script that
   writes it):

   ```
   Version:       2.7.6
   Update URI:    https://github.com/joyrmac/BlissBoogie
   Scout Release: blissboogie
   ```

   `Scout Release` is the theme's folder name. Keep the existing Version
   line; bump it for every release as now.
2. Copy `theme-release.yml` from this folder to `.github/workflows/` in the
   client repo and set the three EDIT values: the path that changes when the
   theme does, the slug, and the theme folder (or a built zip in the repo).
3. Merge to main. The workflow publishes `blissboogie-v2.7.6` with
   `blissboogie.zip`. Each later Version bump on main publishes again.

## Once per site (in WordPress)

1. GitHub → Settings → Developer settings → Fine-grained tokens → Generate:
   - Resource owner: joyrmac. Repository access: only the client's repo.
   - Permissions: **Contents: Read-only**. Nothing else.
   - Expiry: the longest allowed, with a calendar note to renew it.
2. On the site: **Scout → Build**, Theme box → paste the key → **Save key**.
   It is stored encrypted with the site's own salts. Or put
   `define( 'SCOUT_THEME_TOKEN', '…' );` in wp-config.php.
3. The Theme box then shows Up to date or the new version ready, and the
   theme updates itself within a few hours of each release, like the
   plugins. **Check for new versions** on the same screen asks right away.

## Good to know

- The key goes to api.github.com only. GitHub answers with a signed file
  address for the zip, which is fetched without the key.
- A theme without `Update URI` keeps updating by upload, exactly as before.
- An expired or wrong key shows "GitHub turned the key down" on Scout →
  Build; the site keeps running its current theme.
