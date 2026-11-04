# Tools

The shared kits every Scout plugin and theme copies in, and the checks and build scripts that keep
releases safe. Run every command from the repo root.

## What's here
- `admin-kit/`: the shared frame for every Scout dashboard screen (`class-scout-admin-ui.php` and
  `scout-admin.css`). Every plugin and theme ships an identical copy; CI checks the copies match.
- `theme-kit/`: files every Scout theme copies in, such as the "Install Scout Build" prompt. Its own
  `README.md` lists them.
- `theme-release/`: the workflow that lets a client theme update itself from its own repo's
  releases. Its own `README.md` has the setup.
- `core-contract.sh`: fails CI when a Scout Core change would break a plugin that calls it.
- `theme-standard-check.py` and `theme-standard-check.sh`: check a theme against the Scout Site
  Standard (required files, nothing a theme never holds).
- `design-build.php`: builds a theme's `theme.json` tokens and `assets/css/design-tokens.css` from
  its `DESIGN.md`, and fails when the contrast pairs miss 4.5:1.
- `test-build.sh`: builds a labeled test zip of one plugin for a staging site.
- `slop-check.py`: the code slop check. This is the original; copies elsewhere are edited here first.
- `tests/`: PHP checks for the admin kit groups, design module, email guard, fleet checks, forms
  guard, newsletter, Site log, Site Sync and staging check. Two of them (`newsletter.php`,
  `site-sync.php`) wipe data and run only against a disposable local WordPress.

## Who owns it
Joy and Sean at Scout Media.

## Boss doc
`RELEASING.md` for how these tools gate a release, and the Scout Site Standard
(`Scout-Internal/ops/playbook/site-standard.md`) for what the theme checks enforce.

Keep this README current: a change to this folder's structure updates this file in the same pull
request (Scout Repo Standard rule 25).
