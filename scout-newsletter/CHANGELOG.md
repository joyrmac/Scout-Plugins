# Scout Newsletter changelog

## 0.1.3 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.1.2 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.1.1 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.1.0 (2026-11-03)

- First release. Sends people who tick a newsletter box to Beehiiv, tagged by where they joined: Scout Events sign-ups, paid tickets and shop orders (classic checkout and the checkout block), the Scout Members application, Gravity Forms (a per-form Newsletter consent field), the `[scout_newsletter]` sign-up box and block, and any plugin that fires `scout_newsletter_optin`.
- Every agreement is saved with the date, the page and the words shown, and downloads as a CSV. Nobody is added without a ticked box, and someone who unsubscribed is never added back.
- Sends run in the background with retries (Action Scheduler with WooCommerce, WP-Cron otherwise), so a Beehiiv error never stops a checkout or a form. Sending stays off until an operator connects Beehiiv and switches it on.
- Scout, Newsletter: the API key (stored encrypted, or `SCOUT_BEEHIIV_KEY` in `wp-config.php`), publication ID, Test connection, the tag map, Beehiiv options, the consent records and a privacy-safe activity log.
