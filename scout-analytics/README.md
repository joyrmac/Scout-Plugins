# Scout Analytics

Google Tag Manager on the live site, with Google consent mode set up first, a
small cookie notice, and one way for every Scout feature to report clicks.

## Standard: Tag Manager on every client site

**Every Scout client site loads Google Tag Manager** (Sean's standard). GA4,
conversion tags and anything else go inside the container, so Sean manages
tags in one place. This plugin only loads the container. A bare GA4
measurement ID is the fallback for a site with no container, and it is
ignored whenever a container ID is set, so a visit is never counted twice.

Never paste a Tag Manager or GA4 snippet into the theme or another plugin.
Put the ID here.

## What it does

- **Consent first.** Google consent mode defaults print in `<head>` before the
  tag loads. Ad signals stay denied unless the site runs paid ads (setting).
- **Opt-out by default** (our US standard): visits are counted and a small
  notice offers "No thanks". "Ask first" waits for a yes. A browser sending
  Global Privacy Control counts as a no. The choice is kept for 180 days.
- **Live site only.** Nothing loads on staging, local copies, or for people
  logged in who can edit (setting).
- **Privacy choices.** `[scout_privacy_choices]`, or any link to
  `#privacy-choices`, reopens the notice.
- **Click reporting** for every Scout feature:
  - `data-scout-track="event_name"` on a link reports a click
    (`link_label`, `link_url`; `data-scout-label` overrides the label).
  - `data-scout-view="event_name"` reports once on page load.
  - `window.scoutTrack( 'event_name', { ... } )` from scripts.
  - With Tag Manager the event goes into the `dataLayer` (make a Custom Event
    trigger per name); with only GA4 it is sent with `gtag()`.

Events in use: `card_view`, `card_save_contact`, `card_call`, `card_email`,
`card_book`, `links_click`, `links_post_click`, `scout_consent_update`.

## Settings

Scout Build (or Site Build) → Analytics.

| Setting | Default |
|---|---|
| Tag Manager container ID | none |
| GA4 measurement ID (only without Tag Manager) | none |
| Before a visitor chooses | Count, with opt out |
| Ad signals | Off |
| Notice text | Standard wording |
| Privacy policy page | The site's privacy page |
| People editing the site | Not counted |

Filter `scout_analytics_is_live` overrides the live-site check.
