# Scout Events

Events, sessions and speakers that the owner edits in plain forms. Each event
gets a page with its details, ticket button, schedule and speakers, and Event
schema so it can appear in Google's event results.

Built for Bliss Boogie first and shaped for larger multi-day events (several
rooms or tents a day, dozens of speakers). It works with any ticket link
today: a partner's checkout, Acuity, Eventbrite or the site's own shop. Sales,
QR tickets and check-in are planned for a WooCommerce ticket engine later;
this plugin stays the content and display layer in front of it.

## Where it lives

**Events** has its own item in the dashboard sidebar, marked with the Scout
bolt (a plain calendar icon on partner sites with `SCOUT_HUB_BRAND` false), so
it reads as Scout's and sits apart from the site's Build settings. Under it:
**Overview** (the numbers, a **Needs you** list of everything waiting on a
person, and Coming up), **All events**, **Sessions**, **Speakers**, **Import**
and **Settings**. Other Scout features add their screens to this menu on the
`scout_events_menu` action and their items to Needs you with the
`scout_events_needs_you` filter. Anyone who can edit posts can manage events;
only administrators change settings.

## The content

| Type | Holds | Address |
|---|---|---|
| Event | Dates and times, status, how people attend, venue and address or online link, ticket link and button words, availability, price range, sales dates, minimum age, capacity, What to know lines, schema type. Title, description, excerpt and photo are the post's own. | `/event/name/` |
| Session | Event, day, start and end, room or tent, track, kind (class, keynote, performance, activity, break), seats, speakers. | `/session/name/` |
| Speaker | What they do, short bio, website, social links. Photo is the featured image; full bio is the editor. | `/speaker/name/` |

Address words can be changed under Settings on the Events screen. None of the
types has an archive, so a site's own /events/ page is never taken over.

Every event shows a **Ready to share** checklist (start date and time, place,
description, photo, ticket link while on sale, price for Google). The Events
overview lists what each upcoming and draft event still needs.

## Showing events

- Event, session and speaker pages fill themselves in. Turn that off with the
  `scout_events_auto_content` filter when a theme template places the pieces.
- `[scout_events]` upcoming events; `show="past"` or `show="all"`, `limit="6"`.
- `[scout_event id="12"]` one event's details panel and ticket button.
- `[scout_schedule event="12"]` the schedule by day and time;
  `layout="grid"` puts rooms side by side and scrolls sideways on phones.
- `[scout_speakers event="12"]` speaker cards; without `event`, every speaker.
- `scout_events_upcoming()`, `scout_event_data( $id )` and
  `scout_events_schedule_html( $id )` for themes and other Scout features.

Session descriptions open in place with `<details>`, so no script loads. The
stylesheet loads only on pages that show events. Colors are custom
properties: `--scout-events-accent`, `--scout-events-on-accent`,
`--scout-events-line`, `--scout-events-inset`, `--scout-events-radius`.

The ticket button carries `data-scout-track="ticket_click"` and the map link
`data-scout-track="directions_click"`, which Scout Analytics reports.

## Bringing in a spreadsheet

Scout → Events → **Bring in a spreadsheet** takes up to three CSV files, read in
this order so sessions can point at what came before:

| File | Columns |
|---|---|
| events | title, slug, start_date, start_time, end_date, end_time, status, mode, venue, street, city, region, postal, country, online_url, availability, ticket_url, ticket_label, price_from, price_to, sales_start, sales_end, min_age, capacity, know, schema_type, excerpt, description, photo, post_status |
| speakers | name, slug, role, short_bio, bio, website, links, photo, order, post_status |
| sessions | title, slug, event, date, start, end, room, track, kind, speakers, capacity, description, post_status |

Dates are `YYYY-MM-DD`, times `HH:MM`. `links`, `speakers` and `know` take
several values separated by `|`. `event` and `speakers` match by slug, then by
title or name. `photo` is a file name already in the Media Library or a web
address. Unknown columns are ignored.

Every run is a preview until "Bring them in" is ticked. A row updates the
entry with the same slug or title (sessions: same title in the same event),
only for cells that have a value, so running the same files twice changes
nothing. Notes list rows that could not be matched or read.

## Schema

- Event pages: an Event node (or EducationEvent, Festival, SocialEvent) with
  start and end, eventStatus, eventAttendanceMode, Place with PostalAddress or
  VirtualLocation, Offer or AggregateOffer (price, currency, availability,
  sales dates), organizer, performers from the sessions, typicalAgeRange and
  capacity. No node without a start date.
- Speaker pages: a Person node with jobTitle, description, image and sameAs.
- With Scout Core active the nodes join its graph (`scout_schema_graph`) and
  the organizer points at `/#business`. Without it the plugin prints its own
  small graph.
- Off under Settings, or with the `scout_events_schema` filter, when a theme
  prints its own. Adjust nodes with `scout_events_event_schema` and
  `scout_events_person_schema`.

## Data

Each post keeps one protected meta array (`_scout_event`, `_scout_session`,
`_scout_speaker`); a few sortable copies (`_scout_event_start`,
`_scout_event_end`, `_scout_session_event`, `_scout_session_start`) exist only
for ordering lists. Dates and times are in the site timezone. Deleting the
plugin removes its settings only; the content stays.

## Not in this release

Ticket sales and QR check-in (WooCommerce engine), per-attendee waivers,
ticket transfers, vendor and presenter applications, and filling events from
`scout-setup.json` (Scout Build needs an `events` section first).
