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

**Events** has its own item in the dashboard sidebar, right under Scout, with the same Scout
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
  Lists longer than eight fall under month headings; `group="month"` or
  `group="none"` chooses. Speaker cards carry `id="their-slug"` for links.
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

Other Scout features add to the event pages with filters:
`scout_events_detail_rows` (rows of the details panel), `scout_events_ticket_html`
(the ticket button or its stand-in) and `scout_events_card_tags` (the small
tags on event cards). `scout_events_imported_event` runs after each events.csv
row with every column, so a feature can read its own (Scout Members reads
`members_only`).

The ticket button carries `data-scout-track="ticket_click"` and the map link
`data-scout-track="directions_click"`, which Scout Analytics reports.

## Sign-ups and check-in

Tick **Take sign-ups on this site** under Tickets on an event (with Capacity,
Sales open and close, and how many guests each person may bring). The event
page then shows a short form in place of the ticket button. Each person gets
an email with their ticket: a link to their ticket page and the QR code as an
image. The QR code holds that same link.

At the door, sign in on a phone and scan each ticket with the camera. The
link opens the ticket with one big **Check in** button and the running
count. **Events, Sign-ups** is the door list for anyone without their ticket,
the waitlist (Move in emails their ticket), and a spreadsheet download.

Each upcoming event offers **Add to calendar** (Google, or an .ics file for
Apple and Outlook), and the ticket email carries the .ics too. The day before,
everyone going gets one reminder with their ticket.

Sign-ups live in their own table (`wp_scout_event_signups`), created on
activation and on update. Deleting the plugin keeps it, like every other
piece of the client's content. Paid tickets will write into the same table.

## Speaker photos

Each speaker can have several photos. The **Photos** box on their edit screen
shows them all: tick **Main photo** to choose the one on their card and page,
**Add photos** from the Media Library, and tick **Show the other photos on
their public page** for a small strip under their bio. Replacing the main
photo keeps the old one as an option. Presenters can add up to four from
their profile, approved like any other change.

## Directories: speakers, classes, vendors

`[scout_directory]` lists every published speaker as a card (photo, name,
role, short bio, topics) with a search box and topic buttons. People type a
name, practice or word, or tap a topic; the list filters as they go, and the
address keeps the choice (`?se_topic=yoga&se_q=breath`) so it can be shared.
Without JavaScript the same search and buttons reload the page filtered.
On a phone the cards turn into a compact list.

- `type="classes"`: every class, talk, keynote, performance and activity (not
  breaks). A class taught more than once is one card with each time and room.
  `show="upcoming"` keeps only classes at events still to come.
- `type="vendors"`: vendors with their logo, town and what they sell.
- `topic="yoga"` starts on one topic; `event="summit-2026"` keeps one event;
  `region="midwest"` keeps one region; `search="no"` leaves out the search box.
- On a members-only page (Scout Members) it is a private directory.

Topics are tags on speakers, sessions and vendors (the **Topics** box on each,
or **Events, Topics**), or a `topics` column in the CSV files (`Yoga|Breath`).

## Tickets from a WooCommerce shop

When the site runs WooCommerce, each event has a **Tickets from the shop**
box. Tick the products that are its tickets, one per ticket type (Weekend
pass, Day pass, VIP, a camping spot), each with its own price and stock.

- The event page lists them in place of the single ticket button: price,
  "12 left" when stock runs low, **Sold out**, and **Add to cart** (it works
  without JavaScript too). Products with choices link to their own page.
- The event's price, and whether it is on sale or sold out, come from the
  products, so event cards, the details panel and Google's event listing
  follow the shop. "Not on sale yet" on the event still holds the list back
  until the owner opens sales; Cancelled always wins.
- Each product page says which event it is for, and the events list has a
  **Tickets sold** column.
- Every paid order fires `scout_events_ticket_bought` ( $order, $event_id,
  $product_id, $qty ) once per ticket line.

QR tickets and gate scanning come from a WooCommerce ticket plugin such as
FooEvents, working on the same products. Free sign-ups with QR check-in stay
with **Sign-ups** above.

## Regions

For events that run in more than one place. Set the region once, on the
event (**Regions** box, or a `regions` column in events.csv). Its sessions,
speakers and vendors belong to that region too; any of them can also carry a
region of its own. When there is more than one region, directories show
region buttons next to the topics.

- `[scout_regions]`: one card per region with its next event, linking to the
  region's page (Events, Regions, edit a region, Region page).
- `[scout_events region="midwest"]`: one region's events.
- The event's details show its region, linked to the region's page.

## Volunteers

Fill in the **Volunteer shifts** box on an event (job, day, from, to, how many
people, a note for volunteers) and put `[scout_volunteer event="..."]` on a
page. Volunteers pick shifts and get a confirmation, a link to see or drop
their shifts, and a reminder the day before. **Events, Volunteers** is the
roster, with open spots and a spreadsheet download.

## Vendors

`[scout_vendor_apply]` (add `event="summit-2027"` to file them under one
event) is the application for people who want to sell. Each one arrives as a
pending **Vendor** under Events and in Needs you. The owner fills in or fixes
the public details (name, description, logo, city, website, events) and clicks
Publish, which approves them and sends an email. Approved vendors show as a
Vendors list near the end of every event page they are at, and anywhere with
`[scout_vendors event="..."]`. Vendors have no pages of their own.

Contact details (name, email, phone, power and setup needs) live in a private
box on the vendor's edit screen, never on the site, and are in the WordPress
privacy export and erase tools. vendors.csv imports public details only.

## Presenters

Put `[scout_presenter_portal event="summit-2027"]` on a page (the event's
address word links the proposed classes to it; leave it out to link them later).

- **Logged out:** the application. It creates a Presenter account (WordPress's
  own set-password email), a speaker in **Pending Review** with their photo, and
  draft sessions for up to two class ideas.
- **Logged in:** their status, their sessions, and a profile form. Changes wait
  for approval; phone and room needs save straight away (organizers only).
- **The owner:** new applications and waiting changes lead **Needs you**.
  Publish a pending speaker to approve them (they are emailed). The speaker's
  edit screen shows the private details and any waiting changes side by side
  with **Approve changes** and **Discard**.
- Private details live in `_scout_speaker_private` and never reach the site,
  schema or the import. Presenters are sent away from wp-admin.
- Mail goes through `wp_mail`, so the site needs working email (on WP Engine,
  an SMTP plugin or the host's mail) for the set-password and approval emails.

## Bringing in a spreadsheet

Scout → Events → **Bring in a spreadsheet** takes up to three CSV files, read in
this order so sessions can point at what came before:

| File | Columns |
|---|---|
| events | title, slug, start_date, start_time, end_date, end_time, status, mode, venue, street, city, region, postal, country, online_url, availability, ticket_url, ticket_label, price_from, price_to, sales_start, sales_end, min_age, capacity, know, schema_type, excerpt, description, photo, regions, post_status |
| speakers | name, slug, role, short_bio, bio, website, links, photo, photos, topics, regions, order, post_status |
| sessions | title, slug, event, date, start, end, room, track, kind, speakers, capacity, description, topics, regions, post_status |

`region` on events is the state in the address; `regions` is the event's regions (Midwest, Southeast). Dates are `YYYY-MM-DD`, times `HH:MM`. `links`, `speakers`, `know`, `photos`, `topics` and `regions` take
several values separated by `|`. `event` and `speakers` match by slug, then by
title or name. `photo` is a file name already in the Media Library or a web
address. Unknown columns are ignored.

Every run is a preview until "Bring them in" is ticked. A row updates the
entry with the same slug or title (sessions: same title in the same event),
only for cells that have a value, so running the same files twice changes
nothing. Notes list rows that could not be matched or read.

## Hooks for other features

- `scout_events_person` ( $person ): someone signed up, volunteered, or
  applied as a vendor or presenter. Keys: email, name, role (attendee,
  waitlist, volunteer, vendor, presenter), event (id or 0), and phone and org
  when known. Scout CRM Sync uses it.
- `scout_events_ticket_bought` ( $order, $event_id, $product_id, $qty ):
  a paid shop ticket line.
- `scout_events_event` filter ( $event, $id ): stand in for event fields on
  the site (the shop's price and stock use it).
- `scout_events_imported_event`, `_session`, `_speaker`, `_vendor`
  ( $id, $row ): after each spreadsheet row, for extra columns.

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
