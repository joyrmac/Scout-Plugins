# Scout Newsletter changelog

## 0.1.0 (2026-11-03)

- First release. Sends people who tick a newsletter box to Beehiiv, tagged by where they joined: Scout Events sign-ups, paid tickets and shop orders (classic checkout and the checkout block), the Scout Members application, Gravity Forms (a per-form Newsletter consent field), the `[scout_newsletter]` sign-up box and block, and any plugin that fires `scout_newsletter_optin`.
- Every agreement is saved with the date, the page and the words shown, and downloads as a CSV. Nobody is added without a ticked box, and someone who unsubscribed is never added back.
- Sends run in the background with retries (Action Scheduler with WooCommerce, WP-Cron otherwise), so a Beehiiv error never stops a checkout or a form. Sending stays off until an operator connects Beehiiv and switches it on.
- Scout, Newsletter: the API key (stored encrypted, or `SCOUT_BEEHIIV_KEY` in `wp-config.php`), publication ID, Test connection, the tag map, Beehiiv options, the consent records and a privacy-safe activity log.
