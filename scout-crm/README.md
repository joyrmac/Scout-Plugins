# Scout CRM Sync

One customer record per person, on the client's own site, in FluentCRM (free).
Everyone who buys a ticket, signs up, volunteers, applies as a vendor or
presenter, or joins the membership is added and tagged by what they did, the
event and its region. The client then writes their own emails and segments in
FluentCRM. Built for Prepper Camp Nation's "one customer record across tickets,
purchases and forms" and kept general for any Scout Events or Scout Members
site.

## What it listens to

| From | When | Tags |
|---|---|---|
| Scout Events sign-ups | someone saves a spot | Attendee or Waitlist, Event, Region |
| Scout Events volunteers | someone takes shifts | Volunteer, Event, Region |
| Scout Events vendors | a vendor applies | Vendor, Event, Region (business name as company) |
| Scout Events presenters | a presenter applies | Presenter, Event, Region |
| Scout Events + WooCommerce | a ticket order is paid | Ticket buyer, Ticket: (type), Event, Region |
| Scout Members | applied / approved / paused, ended or not accepted | Member applicant / Member + Plan: (name) / Member removed |

Tag names are `Event: (event name)`, `Region: (region name)`, `Plan: (plan)`
and `Ticket: (ticket type)`, made in FluentCRM the first time they are used.

## Consent

New people are added as **transactional**: FluentCRM can send them receipts
and event notes, and they join marketing emails only when they subscribe
through a signup form. Someone already in FluentCRM keeps their status
(subscribed, pending double opt-in, or unsubscribed). A CRM error never stops
a ticket, sign-up or application.

## Where it lives

**Events, Customer list** (administrators): whether the sync is on, and
**Bring them in**, which adds everyone from before it was switched on, tagged
the same way. People are matched by email, so running it twice changes
nothing.

## Hooks

- `scout_crm_tags` filter ( $tags, $context ): change the tag names.
- `scout_crm_contact` filter ( $data, $context ): add FluentCRM fields.
- Listens to `scout_events_person`, `scout_events_ticket_bought` and
  `scout_members_status_changed`.

Needs FluentCRM. Without it the plugin does nothing and says so on its page.
