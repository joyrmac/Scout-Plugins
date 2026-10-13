# Scout Hours

Weekly hours and holiday closures in one place. The site shows them, keeps an
"Open now" line current, adds them to the schema Google reads, and reminds the
owner to set the same special hours on their Google Business Profile.

## Settings

Scout Build (or Site Build) → Hours. Falls back to Settings → Hours on a site
without a Build section.

| Part | What it does |
|---|---|
| Hours confirmed | Off by default. Until it is ticked, nothing shows on the site or in schema, and no Google reminders appear. Editors see a short note where `[scout_hours]` sits. |
| Weekly hours | Each day is open or closed, with one range and an optional second range (a lunch break). A closing time at or before the opening time runs past midnight. |
| Holiday closures | A date or date range, closed all day or open with special hours, and an optional note for visitors. Past entries drop off the screen; entries that ended over a month ago are removed when a new one is added. |
| Notice bar | Off, on closure days, or from a week before through the closure. Visitors can hide it for their visit. |

## Showing the hours

- `[scout_hours]`: the status line and the hours table, with upcoming holiday
  hours (next 30 days) listed under it.
- `[scout_hours show="status"]`: only the status line.
- `[scout_hours show="table"]`: only the table.
- `<?php echo scout_hours_html( array( 'show' => 'status' ) ); ?>` in a theme.
- `scout_hours_status()` and `scout_hours_data()` give other features the
  live status and the confirmed hours as plain data (null until confirmed).

The status line uses the site's timezone (Settings → General). The page
carries a two-week plan with every moment already worked out on the server,
and a small script picks the right line every minute, so a cached page still
says "Open now" at the right time. Without scripts the server's line shows.

Front-end CSS and JS load only on pages that show the hours or the bar. Every
color is a custom property (`--scout-hours-open`, `--scout-hours-closed`,
`--scout-hours-line`, `--scout-hours-today`, `--scout-hours-bar-bg`,
`--scout-hours-bar-text`); fonts and text color come from the theme.

## Schema

- **Scout Core active:** `openingHoursSpecification` and
  `specialOpeningHoursSpecification` are added to its business node
  (`/#business`) through the `scout_schema_graph` filter. One business node.
- **No Scout Core:** a minimal node (type `LocalBusiness`, filter
  `scout_hours_standalone_type`) with the same `/#business` @id, the site name,
  URL and hours.
- **Scout themes (scout-media, scout-recon):** they print their own business
  schema, so nothing is printed. The theme can merge
  `Scout_Hours_Schema::properties()` into its node. Filter
  `scout_hours_standalone_schema` overrides this choice.
- Closed days use Google's documented form: opens and closes both `00:00`.
- Filter `scout_hours_schema_properties` adjusts the properties.

## Reminder to update Google

A week before each closure, administrators see a notice on the Dashboard and
the Hours screen with a link to Google Business Profile. "Done, hide this
reminder" hides it for that closure. No Google API is used.

## Events

The notice bar's close button sends `hours_notice_hide` through
`window.scoutTrack` when Scout Analytics is active.

## Data

Options `scout_hours`, `scout_hours_closures`, `scout_hours_dismissed`.
All three are removed when the plugin is deleted.
