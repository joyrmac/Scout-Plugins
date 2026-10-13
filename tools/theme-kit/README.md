# Scout theme kit

Files every Scout theme copies in, so every site has the same back end.

| File | Copy to | What it does |
|---|---|---|
| `scout-requires-build.php` | `inc/scout-requires-build.php`, required from `functions.php` | When Scout Build is missing, admins get one "Install Scout Build" button. It installs the newest release from the release feed (or turns on a copy already there). Scout Build updates itself after that. |
| `../admin-kit/class-scout-admin-ui.php` + `scout-admin.css` | `inc/` and `assets/css/` | The Scout look for the theme's own screen, which sits at Scout → Website → Theme. |

Change a file here first, then copy it into each theme at that theme's next
update. Tested 2026-10-13 in WordPress 6.5 (Playground): newest version picked
from the feed, installed, activated, notice gone.
