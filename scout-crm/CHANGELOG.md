# Scout CRM Sync changelog

## Unreleased (2026-11-05)

- **Visitor data list covers privacy requests.** The contacts live in FluentCRM, so the list now says to export or delete a person there. No code changed.

## 0.1.4 (2026-11-05)

- **Shared admin kit 1.11.0.** Plain menu names: To do, Business, People, Site features, Search, Legal pages and Site status (labels only, the group keys are the same). Ligatures on where the font has them, and one shared copy of the menu finder.
- **Plainer label.** The "Tags it uses" box on Customer list is now "Labels it adds". The tags themselves are unchanged.
- **Shorter help text** on Customer list.

## 0.1.3 (2026-11-05)

- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.1.2 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.1.1 (2026-10-30)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.1.0 (2026-10-23)

- First release. Adds and tags people in FluentCRM from Scout Events sign-ups, volunteers, vendor and presenter applications, paid WooCommerce tickets, and Scout Members status changes. New people come in as transactional; existing people keep their status. Events, Customer list shows the status and brings in everyone from before.
