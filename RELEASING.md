# Releasing a plugin

Client sites update themselves from this repo's GitHub releases. Shipping a fix
to every site is three steps: bump, tag, push.

---

## How the updating works

Each plugin carries an `Update URI:` header pointing at this repo. WordPress
6.5+ (the header landed in 5.8) sees that header, knows the plugin does not come
from wordpress.org, and hands the update check to the plugin itself. The updater
in `includes/class-scout-plugin-updater.php` answers by looking up this repo's
newest release for that plugin.

The update then appears on the client's **Plugins** screen like any other, and
WordPress's own **enable auto-updates** toggle works on it. Nothing extra is
installed on the client site and no third-party updater service is involved.

Sites check for updates roughly twice a day, and the lookup is cached for six
hours, so a release reaches a site within a few hours rather than instantly.

---

## Shipping a release

**1. Bump the version.** In the plugin's main file, update the `Version:` header
and the matching `SCOUT_*_VERSION` constant where the plugin defines one. The
tag and the header must agree or the release build fails on purpose.

**2. Write the changelog.** Add a dated entry at the top of that plugin's
`CHANGELOG.md`. The release notes are pulled from this entry automatically.

**3. Tag and push.**

```bash
git commit -am "scout-seo 0.2.0: fix the canonical on paginated archives"
git push

git tag scout-seo-v0.2.0
git push origin scout-seo-v0.2.0
```

The tag format is `<slug>-v<version>`, so `scout-core-v0.3.1`,
`scout-forms-guard-v1.0.0`, and so on. Each plugin is versioned and released on
its own; tagging one never touches the others.

Pushing the tag runs `.github/workflows/release.yml`, which checks the tag
against the `Version:` header, lints the plugin, builds `<slug>.zip`, and
publishes the release with the changelog entry as its notes.

---

## Checking it worked

The release shows up at
https://github.com/joyrmac/Scout-Plugins/releases with a `<slug>.zip` asset
attached. That asset is what client sites download, so a release without it
offers nothing and sites stay on their current version.

To see the update on a site without waiting for the cache, visit
**Dashboard → Updates** and click **Check again**.

---

## Things that will bite you

**The repo has to stay public.** The updater calls the GitHub API without any
credentials, which is what keeps client sites free of tokens. Making this repo
private silently stops every site from finding updates.

**The zip has to contain the plugin folder.** The workflow zips the folder
itself so it unpacks to `wp-content/plugins/<slug>/`. Hand-built zips of the
loose files install to the wrong place and orphan the old copy.

**Never re-tag a released version.** Sites cache by version number. Ship
`0.2.1` instead.

**Skipping the changelog entry** just means the release notes fall back to a
pointer at `CHANGELOG.md`. Nothing breaks, but the client-facing "View details"
screen is where those notes show up.

---

## The updater file itself

`includes/class-scout-plugin-updater.php` is identical in all five plugins, so
each one can update itself when the others are inactive. Change it in one place
and copy it to the rest:

```bash
for p in scout-schema scout-seo scout-forms-guard scout-rvpark; do
  cp scout-core/includes/class-scout-plugin-updater.php "$p/includes/"
done
```

The class guards itself with `class_exists`, so whichever copy loads first is
the one that runs. Release the suite together after changing it.
