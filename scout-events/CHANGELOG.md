# Scout Events changelog

## Next

- Lower the front-end defaults (starting with `.se-addcal`) to `:where()` so themes restyle them without raising specificity (theme session, 2026-10-23).

## 0.13.0 (2026-10-23)

- **Tickets from a WooCommerce shop.** A Tickets from the shop box on each event picks its ticket products. The event page lists them with price, "N left", Sold out and Add to cart; the event's price and on sale / sold out state, cards and Google listing follow the products; product pages name their event; the events list gets Tickets sold. Paid orders fire `scout_events_ticket_bought` once per ticket line. Only active when WooCommerce is.
- New filter `scout_events_event` ( $event, $id ) on the site side, so other features can stand in for fields. Edit screens still show what was typed.

## 0.12.0 (2026-10-23)

- **Regions.** For events in more than one place (Prepper Camp Nation's six regions). Set once on the event; sessions, speakers and vendors follow, and can carry their own. `[scout_regions]` region cards linking to each region's page, `[scout_events region=""]`, a Region line in event details, Events → Regions, and a `regions` column on all four CSV files.
- **One directory for speakers, classes and vendors.** `[scout_directory type="classes"]` (a repeated class is one card with each time) and `type="vendors"`, with `region`, `show="upcoming"` for classes, and region buttons beside the topics when there is more than one region. Topics now work on sessions and vendors too, with `topics` columns in sessions.csv and vendors.csv.
- More than one directory on a page keeps its own ids.
- New actions `scout_events_imported_session` and `scout_events_imported_vendor` ( $id, $row ).

## 0.11.0 (2026-10-23)

- **Speaker directory.** `[scout_directory]`: every speaker as a card with search and topic buttons, filtering as people type or tap, with the choice kept in the address. Works without JavaScript. Compact cards on phones. `topic`, `event` and `search` options.
- **Topics** on speakers: a Topics box on each speaker, Events → Topics, and a `topics` column in speakers.csv ("|" between).
- New action `scout_events_imported_speaker` ( $id, $row ) after each speaker row, so other features can read their own columns.

## 0.10.1 (2026-10-23)

- Photos box: **No photo for now, on purpose** keeps a speaker out of the "No photo yet" reminder in Needs you, for a speaker whose photo is waiting on approval. Adding a photo clears it.

## 0.10.0 (2026-10-23)

- **More than one photo per speaker** (Joy asked). A Photos box on each speaker shows every photo of them: tick **Main photo** to choose the one on their card and page, add more from the Media Library, and turn on a small strip of the others on their public page. Swapping the main photo by any route (the Featured image box, an approved presenter change, an import) keeps the old one as an option. Presenters can send up to four more photos from their profile, and they wait for approval like any other change. speakers.csv takes a `photos` column (file names separated by `|`). When a speaker already has a main photo, the import adds a different one from the photo column as an option instead of replacing it.

## 0.9.0 (2026-10-23)

- **Volunteer shifts.** A Volunteer shifts box on each event lists the jobs it needs (job, day, from, to, how many people, a note). `[scout_volunteer event="..."]` shows them by day with spots left, and volunteers pick one or more with their name, email and phone. They get a confirmation listing every shift, a link to see or drop their shifts, and a reminder the day before. **Events, Volunteers** is the roster: each shift with who is on it, what is still open, Take off shift, and a spreadsheet download. Open spots in the next two weeks show in Needs you. Volunteers live in their own table and are in the privacy export and erase tools.

## 0.8.0 (2026-10-23)

- **Add to calendar.** Every upcoming event shows "Add to calendar: Google · Apple or Outlook" under its ticket button or sign-up form. The .ics file (`?se_ics=1` on the event address) also rides along with each ticket email. Events without a start time go in as all-day.
- **Day-before reminders.** Everyone going to an event that takes sign-ups gets one email in the 24 hours before it starts, with their ticket link and QR code. People who signed up inside that window are skipped, since their ticket just arrived. An hourly check sends them; the sign-ups table gains a `reminded` column on update.

## 0.7.1 (2026-10-23)

- Speaker cards answer to their address word, so a link to `/summit-speakers#nick-blum` lands on that card (the first time a speaker appears on the page).
- Event lists longer than eight fall under month headings, and on phones those lists show name, date, place and price without the summary line. `[scout_events group="month"]` or `group="none"` sets it either way.

## 0.7.0 (2026-10-23)

- **Sign-ups and check-in.** Tick "Take sign-ups on this site" on an event and its page takes sign-ups: name, email and any guests allowed. Each person gets an email with their ticket and a QR code (an image attachment and a ticket page link). At the door, anyone who can edit the site scans the code with a phone camera and taps Check in; no app needed. When the event reaches its capacity, new sign-ups join a waitlist.
- **Events, Sign-ups**: one event's door list with headcounts, a search box, Check in for people without their ticket, Move in from the waitlist (which emails their ticket), cancel and put back, and a spreadsheet download. Works at a phone's width.
- People cancel from their ticket page. Signing up twice sends the same ticket again instead of taking a second place.
- Ticket pages are never cached or indexed. Sign-ups are in the WordPress privacy export and erase tools.
- Waitlisted people while places are open show in Needs you, and the Coming up table shows how many signed up.
- events.csv takes `signup` and `signup_guests`. A `scout_events_signup_allowed` filter lets Scout Members keep members-only sign-ups for members.
- QR codes are drawn on the site by a bundled MIT-licensed library (lib/), so no ticket link is sent to an outside service.
- **Vendors.** `[scout_vendor_apply]` is an application form for people who want to sell at an event; each one arrives as a pending vendor and in Needs you. Publishing approves them and emails them, naming the events ticked. Approved vendors show as a Vendors list on each event page they are at, and anywhere with `[scout_vendors event="..."]`. Contact details (name, email, phone, power and setup needs) stay in a private box on the edit screen and in the privacy tools; the import takes public details only (vendors.csv).

## 0.6.0 (2026-10-22)

- Hooks for Scout Members and other Scout features: `scout_events_detail_rows`, `scout_events_ticket_html` and `scout_events_card_tags` filters on the event panel, ticket button and event cards, and a `scout_events_imported_event` action after each events.csv row, carrying every column. Nothing changes on a site without them.

## 0.5.3 (2026-10-22)

- Import: rows that share a title but name different slugs now stay separate entries. A calendar with Pause & Play in January, April and August imports as three events instead of one updated three times.
- Overview: Needs you lists the three soonest unfinished events one by one and counts the rest in one line that jumps to Coming up, so a year-long calendar no longer buries the other items. "Google" keeps its capital in those lines.

## 0.5.2 (2026-10-22)

- **Events in the top toolbar**, right after Scout and with the same bolt (Joy asked). A small count shows how many things need you; the menu opens Overview, Needs you, Add event, Add session and Add speaker, from the dashboard and from the site.

## 0.5.1 (2026-10-22)

- Fix: a speaker page no longer says the same thing twice when the full bio opens with the short bio (seven 2026 Bliss Boogie presenters, Kai among them). The short bio still feeds cards and schema.

## 0.5.0 (2026-10-22)

- **Presenters apply and keep their own profile.** `[scout_presenter_portal event="..."]` on any page shows an application (name, pronouns, email, phone, what they do, bios, links, photo, up to two class ideas, what they need in the room, a photo release). Sending it creates a Presenter account (WordPress emails them a set-password link), a speaker in Pending Review and draft sessions for their classes. Logged in, a presenter sees their status and sessions and can update their profile.
- **The owner approves everything.** Publishing a pending speaker approves the application and emails them. Profile updates wait on the speaker's edit screen with a before-and-after view and Approve or Discard. New applications and waiting changes lead the Needs you list.
- **Private stays private.** Phone, pronouns, room needs, notes and the photo release show only on the speaker's edit screen, never on the site or in schema.
- Presenters never see the dashboard: it sends them to their profile page, and the toolbar is hidden for them.
- Spam guard on the forms: a hidden field, a minimum time on the form, and a nonce.
- **Events sits right under Scout** in the sidebar with the identical Scout bolt (Joy: "near each other at the top... the same bolt").

## 0.4.1 (2026-10-22)

- **Import keeps one copy when a session changes event.** A row now also matches a session with the same title and the same speaker in any event, so moving a session to its right year on a second run updates it instead of adding a copy.
- Shortcodes take an event's address word as well as its number: `[scout_speakers event="bliss-boogie-2026"]`, so a page keeps working when moved between sites.

## 0.4.0 (2026-10-22)

- **Events has its own place in the sidebar**, marked with the Scout bolt, instead of sitting inside the Scout menu with the site's settings (Joy asked: "so it's clear it's ours"). Under it: Overview, All events, Sessions, Speakers, Import and Settings. Partner sites without Scout branding get a calendar icon.
- Other Scout features join the menu on the `scout_events_menu` action (replaces the `scout_events_hub_tabs` filter from 0.3.0).

## 0.3.0 (2026-10-22)

- **One Events hub.** Scout → Events opens on an Overview: upcoming events, sessions, speakers and the next date at a glance, a **Needs you** list (draft sessions, events missing details, sessions without a time, speakers without a photo, sales that should have closed), and Coming up with Add event, Add session and Add speaker. Tabs lead to every list, the spreadsheet import, and settings. Editors see all of it; only administrators change settings.
- Other Scout features add their own tabs and Needs you items (`scout_events_hub_tabs`, `scout_events_needs_you`, `scout_events_hub_overview`), so tickets, members and applications land in the same place.

## 0.2.0 (2026-10-22)

- **Bring in a spreadsheet.** Scout → Events takes up to three CSV files (events, speakers, sessions) and adds them in one go. Every run is a preview first; tick "Bring them in" to write. Running the same files again updates what is there instead of making copies, and empty cells never wipe a field. Photos come from a file name already in the Media Library or a web address.
- Fix: after saving settings, the screen came back as "not allowed" on sites with a Scout menu. Forms now return to the screen they came from.

## 0.1.0 (2026-10-22)

- First release. Events, sessions and speakers as three content types under Scout Build (or Site Build) → Events, each edited in a plain form: dates and times, status, venue or online link, ticket link and button words, price range, sales dates, minimum age, capacity, and a "What to know" list.
- Event pages fill themselves in: a details panel with the ticket button, the description, What to know, the schedule day by day, and the speakers. Session and speaker pages link to each other.
- Shortcodes for any page: `[scout_events]`, `[scout_event]`, `[scout_schedule]` (list or room grid) and `[scout_speakers]`.
- Event schema (Event, EducationEvent, Festival or SocialEvent) with offers, place, organizer and performers, and Person schema on speaker pages. Joins Scout Core's graph when it is active.
- A "Ready to share" checklist on every event and an Events overview that lists what each upcoming event still needs.
- No archives, so an existing /events/ page on the site is never taken over. Deleting the plugin keeps every event, session and speaker.
