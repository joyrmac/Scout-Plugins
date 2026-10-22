# Scout Site Check changelog


## 0.2.2 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.2.1 (2026-10-14)

- **A privacy link under the button.** The line under Check my site now links to the site's privacy policy ("How we use your details"), so people see how their name and email are used before they send them. It shows when WordPress has a privacy page set (Settings, Privacy).

## 0.2.0 (2026-10-14)

- **A landing-page layout.** `[scout_site_check layout="hero"]` puts the form and the sample report side by side for the top of a page. The default stays stacked.
- **The sample report shows all eight checks,** with a big score, a headline and one colored bar per check. When it scrolls into view the rows resolve one at a time, once (it stays still for visitors who turn motion off).
- **Calmer live run.** A progress bar and one dot per step replace the spinning circles, and the result shows the score as a big numeral instead of a cerulean ring.
- Inputs use the 8px corner from the Scout design system, buttons darken on hover with `--accent-fill-hover`, and focus rings use the brand cerulean.

## 0.1.1 (2026-10-13)

- **Fix: a passing speed line contradicted itself.** A site with a speed score of 90 or more but main content slower than 2.5 seconds read "shows in 3.2 seconds. Google's goal is under 2.5 seconds" next to a green check. It now gives the score and says the time is close to the goal.

## 0.1.0 (2026-10-13)

- **First release.** A free website check visitors run from the site with `[scout_site_check]`. It checks eight things: speed on phones (Google's speed tool), the security lock, page titles, business info for Google, phone layout, AI crawlers (robots.txt plus a test visit as each bot), the sitemap and photo sizes.
- **Results on the page, report by email.** The visitor watches the four steps finish, sees the score and every row, and gets the full report by email with a "Book a call" button. A copy goes to the team with the visitor's contact details.
- **Site checks inbox** under Scout → Business, with the report, Send report, Reply by email and a Followed up box. The sidebar counts checks nobody has followed up on.
- **Review before sending** (off by default) holds each report until someone clicks Send report.
- **Built to be safe to leave on a public page:** a honeypot, limits per visitor, per email and per day, and every request goes through WordPress's safe HTTP calls, which refuse private and local addresses.
