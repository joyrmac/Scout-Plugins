# Scout Build

The Site Build hub: one dashboard screen showing which Scout features a site
runs, with the controls to turn them on, off, or back a version, a way for the
client to request a change, and a quick health check. Each feature stays its
own plugin with its own release. Spec: `../docs/site-build-hub-spec.md`.

Standalone: does not need Scout Core.

## Where it shows up

Inside the site's existing Build menu when there is one (the Scout Media
theme's **Scout Build**, Recon's **Recon Build**, or Scout Core's **Scout**
menu), as **Features**. Otherwise it adds its own top-level section, labeled
**Scout** with the cerulean bolt (or **Site Build** when `SCOUT_HUB_BRAND` is
false).

## Settings in `wp-config.php`

| Constant | Default | What it does |
|---|---|---|
| `SCOUT_HUB_OPERATORS` | `sean@scoutraleigh.com, joy@scoutraleigh.com` | Who can turn features on and off, go back a version, set the plan, and change request statuses. Must also be a WordPress admin. |
| `SCOUT_HUB_REQUEST_EMAIL` | `team@scoutraleigh.com` | Where change requests are emailed. **Set this on partner-side sites** so nothing routes to a Scout address. |
| `SCOUT_HUB_BRAND` | `true` | Labels the menu **Scout** with the bolt and shows the "Built and looked after by Scout Media" sign-off. **Set to `false` on partner-side or white-label sites**: the menu then says Site Build and nothing mentions Scout. |
| `SCOUT_THEME_TOKEN` | (none) | The read-only GitHub key for the theme's own repo, instead of saving it on Scout → Build. See "Theme updates" below. |
| `DISALLOW_FILE_MODS` | (WordPress) | When true, features that are not installed must be uploaded by hand; on and off still work. |

## Theme updates

A client theme can update itself like the Scout plugins, from the client's
own private repo. The theme adds `Update URI: https://github.com/OWNER/REPO`
and `Scout Release: <folder name>` to its `style.css` header, and its repo
publishes each version with the workflow in `tools/theme-release/`. On the
site, an operator pastes a read-only key (a fine-grained GitHub token with
Contents read access to that one repo) into the **Theme** box on the Features
screen. The key is stored encrypted and sent to api.github.com only; the zip
itself is fetched from GitHub's signed address without it.

The Theme box then shows the installed version, "Up to date" or the version
ready, **Update the theme now**, and the theme auto-updates within a few hours
of each release. **Check for new versions** checks the theme too. A theme
without `Update URI` keeps updating by upload. Steps for each client:
`tools/theme-release/README.md`.

## The feature list

`catalog.json` at the root of this repo, mirrored in `scout-build/catalog.json`
(CI fails if the two differ). Each entry: `slug`, `name`, `summary` (one plain
sentence), `tier` (`build`, `partnership`, `whole` or `addon`), `law`,
`requires` (other slugs), and optional `client_specific` (shown only where
installed). Adding a feature to the list needs no hub release: sites pick it up
within 12 hours, or at once with **Check for new versions**.

## Installing from releases

Turn on and Go back download the `<slug>.zip` asset from this repo's GitHub
releases (`<slug>-v<version>` tags), the same releases the updater uses. The
repo's releases must stay public for this to work without a login.
