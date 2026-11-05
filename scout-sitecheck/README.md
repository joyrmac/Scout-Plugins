# Scout Site Check

A free website check visitors run from the site. They enter a web address, a
name and an email, watch the check run (about a minute), see the results on the
page, and get the full report by email. Every request lands in an inbox so the
team can follow up.

Built for scoutraleigh.com as a way to start conversations with new clients.
It is listed in the catalog as client-specific, so it only shows on sites that
have it installed.

Design: `Scout-Internal/content/design/scout-features/DESIGN.md` 5.18
(landing) and 5.19 (emailed report).

## The eight checks

| Check | How | Pass |
|---|---|---|
| Speed | Google PageSpeed Insights, phone | Score 90+ (warn 50 to 89, fail under 50) |
| Security lock | Home page over HTTPS; certificate expiry | HTTPS works, certificate has 14+ days |
| Page titles | Home page plus up to four linked pages | Every page has its own title |
| Business info for Google | JSON-LD and microdata | A business type with an address or phone |
| Phone layout | The viewport tag | Fits the screen and lets people zoom |
| AI crawlers | robots.txt for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, plus a test visit as the first three | None blocked |
| Sitemap | robots.txt Sitemap lines, then the usual addresses | A real sitemap file |
| Photo sizes | Up to 15 home page images, by file size | None over 500 KB |

A check that can't run (Google's speed tool busy, no measurable photos) shows
as "not checked" and is left out of the score.

## How a check runs

The browser calls `scout-sitecheck/v1/start`, then `step` four times (basics
first, then speed, crawlers and photos side by side), so no single request runs
long enough for the host to cut it off. The last step to land finishes the
report and sends it, exactly once. If the visitor closes the tab, a scheduled
job finishes the run ten minutes later.

## Settings: Scout → Site features → Free site check

Requests land in **Check requests** in To do.

- **Make the page (as a draft):** a "Free website check" page at `/site-check/`
  with the shortcode. Publish it when it's ready.
- **Email new checks to:** gets a copy of every check, and is the reply address
  on the report. Blank uses Send notices to in Business info.
- **Who follows up:** the report says "Reply to this email and Sean will walk
  you through them." Blank: "we'll walk you through them."
- **Their photo:** a JPG or PNG link for the email.
- **Booking page:** where "Book a call" goes. Blank uses the theme's booking page.
- **Review before sending:** hold reports until someone clicks Send report.
- **Google's speed tool key:** a free PageSpeed Insights API key. Without one,
  Google shares a small allowance across everyone and often refuses. Or define
  `SCOUT_SITECHECK_PSI_KEY` in `wp-config.php`, which wins.

## Shortcode

`[scout_site_check]` shows the form and a sample report. `sample="no"` leaves
the sample out.

## Limits

3 checks per visitor per hour, 3 per email per day, 40 per site per day.

## What this feature needs from the theme

- Tokens the form and report read, each with a fallback: `--bg`, `--surface`, `--surface-2`, `--text`, `--text-2`, `--text-3`, `--line`, `--accent`, `--accent-fill`, `--accent-fill-hover`, `--accent-text`, `--positive`, `--caution`, `--negative`, `--font-mono`.
- A page holding `[scout_site_check]`. The settings screen's "Make the page" button creates it. The theme only needs to link to it.
- Nothing to call. The styles load only on the page that shows the form.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| What a person types into the site check form: their name, their email address and the website address they want checked, plus the check results for that website, the address of the page the form was on, and when the report was sent (When someone runs a check with the [scout_site_check] form.) | This website's database, as private site check records | Until someone deletes it. Deleting the plugin keeps these records. The site's privacy tools export and delete a person's check requests by email address. | Emailed to the visitor (their report) and emailed to the site's own address (the address on the Free site check screen, or when that is blank, Send notices to in Business info, or the site admin email) |
| The website address the person asked to check (During every site check.) | Sent to Google's speed tool to measure the site's speed on phones | Not kept there by this website | Google PageSpeed Insights |
| A scrambled (hashed) form of the visitor's IP address and a scrambled form of their email address, each with a count of checks, used to limit checks to three an hour per connection and three a day per email (When someone submits the site check form with a valid name, email and web address.) | A temporary record in this website's database | One hour for the IP address record and one day for the email record | Nobody |

To run the check, this website also visits the address the person entered, the same way a browser would. Only the website address goes to Google, never the person's name or email.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
