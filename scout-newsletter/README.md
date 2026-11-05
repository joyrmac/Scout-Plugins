# Scout Newsletter

Sends people who tick the newsletter box on a Scout site to the client's email
list in Beehiiv, tagged by where they joined. Built for Bliss Boogie (Kai's
newsletter, Elevate, lives in Beehiiv; her customer list lives in FluentCRM
through Scout CRM Sync) and kept general for any Scout site with a Beehiiv
publication. The catalog's Newsletter feature ("theme-only today, needs a
plugin before clients get it") ships here.

## The rules it keeps

- **Nobody is added without a ticked box.** The box always starts unticked and
  is never required. A purchase, a booking or a membership alone never adds
  anyone. There is no import: existing customers are invited, not added
  (Bliss Boogie FOR-KAI #116).
- **Someone who unsubscribed stays unsubscribed.** Every send first asks
  Beehiiv whether the email is on the list. Unsubscribed (`inactive`) or
  invalid: nothing is sent, the site remembers it, and later ticks are saved
  but never sent. The create call always says `reactivate_existing: false`.
- **The visitor never waits.** Ticking a box writes one row and queues it.
  Beehiiv is called in the background (Action Scheduler when WooCommerce has
  loaded it, WP-Cron otherwise), so an API error never stops a checkout, a
  booking or a form.
- **Every agreement is kept.** Who (email, first name), when (UTC), where
  (source, page, campaign) and the exact words shown next to the box, for
  CAN-SPAM and for the owner's own proof. Download them all as a CSV from
  Scout, Newsletter.
- **Logs hold no personal data.** The activity log shows a 12-character
  one-way code per person (HMAC-SHA256 with the site's salt), never a name or
  email, and never a Beehiiv response body.

## Settings (Scout, Newsletter; operators only)

Operators are the Scout Build list (`SCOUT_HUB_OPERATORS`); on a site without
Scout Build, administrators. Everyone else with access sees the status only.

| Setting | What it does |
|---|---|
| Beehiiv API key | Stored encrypted with the site's salts (the way Scout Build stores the theme key), or set `SCOUT_BEEHIIV_KEY` in `wp-config.php`, which wins and keeps it out of the database. |
| Publication ID | `pub_...`, or `SCOUT_BEEHIIV_PUBLICATION` in `wp-config.php`. |
| Sending | Off by default. While off, ticked boxes are saved as **held**. Once connected and on, **Send them to Beehiiv** releases held sign-ups (leave them on a staging copy). |
| Test connection | Reads the publication and shows its name, or what went wrong. |
| Tags by where they joined | One tag (or a comma list) each for event sign-up, paid ticket, membership joined, booking, and form or sign-up box. Beehiiv allows 100 tags per publication, so tag by type, never one per event. |
| Confirmation email | Beehiiv's own double opt-in setting (default), always, or never (`double_opt_override`). |
| Welcome email | Beehiiv's welcome email for new sign-ups (`send_welcome_email`, off by default). |
| Automation ID | Optional automations with an "Add by API" trigger (`automation_ids`). |
| Source name | `utm_source` (the site's host by default). Where they joined goes as `utm_medium`. |
| Custom fields | Names of Beehiiv custom fields for first name, "joined from" and member plan. Beehiiv drops names it does not know, so each must match a field already made there. Empty means not sent. |
| Keep subscribers up to date | Off by default. When on, event sign-ups, paid tickets and membership changes add the type tag (and the member plan field) for people **already** on the list. It never subscribes anyone: a 404 from Beehiiv ends it. |
| The box on this site | Newsletter name, the words next to the box, an optional WooCommerce checkout box (always on for checkouts with event tickets), the Scout Members question to watch, and the sign-up box's thank-you line. |

## Where ticked boxes come from

| From | How | Source |
|---|---|---|
| Any plugin | `do_action( 'scout_newsletter_optin', $email, $first_name, $source, $tags, $context )` | as given |
| Scout Events sign-ups | `scout_events_person` with `newsletter => true` and `newsletter_text` (the words shown) | `event` (volunteers, vendors, presenters: `form`) |
| Tickets and the shop | The checkout box (classic checkout and the checkout block, through WooCommerce's additional checkout fields). Sent once the order is paid (processing or completed), once per order. Also listens to `scout_events_ticket_bought`. | `ticket` (an order with no ticket: `form`) |
| Scout Members | A tick-box (`check`) question on the application: the one named in the settings, or any that mentions the newsletter or its name. Sent at application. Approval adds the membership tag only through "Keep subscribers up to date". | `membership_application` |
| Gravity Forms | Form Settings, **Newsletter**: pick the **Newsletter consent field** (a Consent or Checkboxes field) and an optional extra tag. Spam entries are ignored. The Consent field's saved words are what gets recorded. | `form` |
| Sign-up box | `[scout_newsletter heading="" button="" name="yes" tag=""]` or the **Newsletter sign-up** block. Nonce, honeypot, and a signature over its source and tag so a visitor cannot choose their own. | `form` |

### One box at the checkout

This plugin owns the one newsletter box at the WooCommerce checkout. While it
is active, Scout Events prints no ticket box of its own: a checkout holding
event tickets shows this box even with the checkout box setting off, so ticket
buyers are still asked. Scout Booking asks on its booking form, so a checkout
that only pays for bookings skips this box when the booking form already asked.
The `scout_newsletter_checkout_box` filter (true or false) is how they steer
it. One box, one consent record, tagged `ticket` when the order holds tickets.
Orders placed with the older Scout Events box (it saved the time it was ticked)
are recorded with that box's words, "Send me the newsletter".

`$context` takes `wording` (the exact words next to the box), `url` (the
page), and `campaign` (sent as `utm_campaign`, for example an event's slug).
Calling the action means the person ticked a box; only call it then.

A second tick for the same email and source within ten minutes joins the first
record, so two plugins reporting the same checkout make one send.

## What happens to each sign-up

1. `GET /subscriptions/by_email/{email}`. Unsubscribed or invalid: skipped and
   remembered. On the list: go to step 3.
2. Not on the list (404): `POST /subscriptions` with `reactivate_existing:
   false`, `send_welcome_email`, `utm_source`, `utm_medium`, `utm_campaign`,
   `referring_site`, `custom_fields`, and the optional `double_opt_override`
   and `automation_ids`. An `inactive` answer is treated as unsubscribed and
   gets no tags.
3. `POST /subscriptions/{id}/tags` with the tags.

No answer, a 429 or a 5xx: tried again after 5 minutes, 15 minutes, an hour,
3 hours and 12 hours, then marked failed. A retry after a successful create
only re-sends the tags. A 401 or 403 (key refused) holds the sign-up and
stops the batch until the key is fixed. An hourly sweep picks up anything a
missed cron left behind. At most 20 sign-ups (about 60 calls) per run, inside
Lite's 180 requests a minute.

## Beehiiv API v2, as read on 2026-11-03

Auth on every call: `Authorization: Bearer <API key>`.

| Call | Endpoint | Docs |
|---|---|---|
| Test connection | `GET https://api.beehiiv.com/v2/publications/{publicationId}` | https://developers.beehiiv.com/api-reference/publications/show |
| Look up by email (URL-encoded) | `GET /v2/publications/{publicationId}/subscriptions/by_email/{email}` | https://developers.beehiiv.com/api-reference/subscriptions/get-by-email |
| Create subscription | `POST /v2/publications/{publicationId}/subscriptions` | https://developers.beehiiv.com/api-reference/subscriptions/create |
| Add tags (made on first use) | `POST /v2/publications/{publicationId}/subscriptions/{subscriptionId}/tags` with `{ "tags": [...] }` | https://developers.beehiiv.com/api-reference/subscription-tags/create |
| Update custom fields | `PUT /v2/publications/{publicationId}/subscriptions/by_email/{email}` with `{ "custom_fields": [{ "name", "value" }] }` | https://developers.beehiiv.com/api-reference/subscriptions/update-by-email |
| Rate limits (Lite: 180 a minute) | | https://developers.beehiiv.com/welcome/rate-limiting |

Subscription statuses per the docs: `validating`, `invalid`, `pending`,
`active`, `inactive` ("possibly due to an unsubscribe"), `needs_attention`,
`paused`, `unset`. Custom fields must already exist on the publication; unknown
names are dropped with a warning and the subscription is still created.

## Hooks

- `scout_newsletter_optin` action: described above.
- `scout_newsletter_tags` filter ( $tags, $source, $email_hash ): change the tags.
- `scout_newsletter_beehiiv_body` filter ( $body, $row ): change the create body.

## Tests

`php tools/tests/newsletter.php /path/to/wordpress` against a disposable local
WordPress (it refuses any home URL that is not localhost or 127.0.0.1) with
this plugin active. Every Beehiiv call is answered by the `pre_http_request`
filter: subscribe, tag, unsubscribed skip, API down then retry, refused key,
missing key, double ticks, updates that never subscribe, Gravity Forms, Scout
Events, the sign-up box, the log's privacy, and the create body checked
against the documented schema. With WooCommerce and Scout Members active it
also covers Action Scheduler, the checkout box and the application question.

## Not in 0.1.0

- Beehiiv webhooks (Lite includes 3). An unsubscribe in Beehiiv is learned the
  next time the person ticks a box, not the moment it happens.
- A Mailchimp connector for Scout's own site.
- Removing tags or unsubscribing from the site.

## Uninstall

Settings, the key and the log go. The consent records stay unless
`SCOUT_PURGE_ON_UNINSTALL` is true in `wp-config.php` (download them first).

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Newsletter sign-ups: email, first name, where they joined (event, ticket, membership, booking or form), tags, the exact words next to the box they ticked, the page address, the campaign (such as the event), the date and time, how the send went, and the Beehiiv subscriber ID (Sent to Beehiiv only when an account is connected and sending is switched on. Until then they wait on the site.) | A newsletter table in this website's database | Until someone deletes it. Deleting the plugin leaves these records in place unless the site turns on the purge setting first. | Beehiiv |
| Email of people who sign up for an event, volunteer, apply, buy a ticket or become a member without ticking the box, with a tag for what they did and their member plan. The email is looked up in Beehiiv; if they are already subscribers their tags are updated, and if not, nothing more is sent and they are not added. (only when "Keep subscribers up to date" is on and sending is switched on) | A newsletter table in this website's database | Until someone deletes it | Beehiiv |
| The words next to the newsletter box ticked at checkout (only when the checkout box setting is on, or the cart holds event tickets) | The WooCommerce order in this website's database | Until someone deletes it | Nobody |
| A log of sends: the time, a short one-way code made from the email (not the email itself), what was done and how it went | A setting in this website's database | The newest 100 lines; older ones are dropped. Removed when the plugin is deleted. | Nobody |
| A short one-way code made from the email of anyone Beehiiv reports as unsubscribed, with the time, so they are never sent again | A setting in this website's database | Until the plugin is deleted | Nobody |

Beehiiv receives the email, the first name (when the owner names a first-name field), how they joined, the page address, the campaign and the tags. Nobody is added without a ticked box, and staff can download the sign-up records as a spreadsheet.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
