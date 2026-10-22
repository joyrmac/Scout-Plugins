# Scout Events changelog

## 0.1.0 (2026-10-22)

- First release. Events, sessions and speakers as three content types under Scout Build (or Site Build) → Events, each edited in a plain form: dates and times, status, venue or online link, ticket link and button words, price range, sales dates, minimum age, capacity, and a "What to know" list.
- Event pages fill themselves in: a details panel with the ticket button, the description, What to know, the schedule day by day, and the speakers. Session and speaker pages link to each other.
- Shortcodes for any page: `[scout_events]`, `[scout_event]`, `[scout_schedule]` (list or room grid) and `[scout_speakers]`.
- Event schema (Event, EducationEvent, Festival or SocialEvent) with offers, place, organizer and performers, and Person schema on speaker pages. Joins Scout Core's graph when it is active.
- A "Ready to share" checklist on every event and an Events overview that lists what each upcoming event still needs.
- No archives, so an existing /events/ page on the site is never taken over. Deleting the plugin keeps every event, session and speaker.
