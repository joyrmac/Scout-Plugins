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

Scout Build (or Site Build) → Visits and cookies.

| Setting | Default |
|---|---|
| Google tracking code (the Tag Manager container ID, GTM-) | none |
| Google tracking code under More options (a GA4 measurement ID, G-, only without Tag Manager) | none |
| Before a visitor chooses | Count, with opt out |
| Ad signals | Off |
| Notice text | Standard wording |
| Privacy policy page | The site's privacy page |
| People editing the site | Not counted |

Filter `scout_analytics_is_live` overrides the live-site check.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Page views: the page address and title, the page the visitor came from, and browser and device details. Google also receives the visitor's IP address with each request. (only on the live site, and not for people logged in to edit it. The Google tag loads for every other visitor; when a visitor says No thanks, their browser sends Global Privacy Control, or (on sites set to ask first) they have not said yes, Google's consent mode tells Google not to use analytics cookies) | Google Analytics, through Google Tag Manager or the Google Analytics tag. This website does not store it. | Kept by Google under the site's own Google Analytics settings. This website keeps no copy. | Google Analytics and Google Tag Manager (Google) |
| Taps on marked buttons and links (such as call, text, book and chat buttons): the button name, its words (up to 80 characters) and the link address (same as page views) | Google Analytics, through Google Tag Manager or the Google Analytics tag. This website does not store it. | Kept by Google under the site's own Google Analytics settings. This website keeps no copy. | Google Analytics and Google Tag Manager (Google) |
| The visitor's answer on the cookie notice (allow or no thanks) (only after the visitor taps a button on the cookie notice) | A cookie in the visitor's own browser, and passed to the Google tag on the page | 180 days in the visitor's browser | Google (as the consent setting sent with Google's tags) |

| Cookie | Why | How long |
|---|---|---|
| `scout_consent` | Remembers whether the visitor allowed visit counting or said no thanks, so the notice does not ask again | 180 days |
| `Google Analytics cookies (_ga, _ga_*)` | Set by Google to count visits and tell new visitors from returning ones | Set by Google; up to 2 years by Google's default |
| `Google ad cookies (such as _gcl_au)` | Set by Google ad tags inside the site's Tag Manager container, to measure ads | Set by Google |

Google's analytics cookies are only set when counting is allowed, and ad cookies stay off unless the site turns on ad signals in Analytics settings and the visitor allows counting. Anything else the Tag Manager container sends is set inside the container by whoever manages it, so check it too.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
