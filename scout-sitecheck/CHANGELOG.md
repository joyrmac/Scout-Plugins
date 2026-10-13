# Scout Site Check changelog


## 0.1.0 (2026-10-13)

- **First release.** A free website check visitors run from the site with `[scout_site_check]`. It checks eight things: speed on phones (Google's speed tool), the security lock, page titles, business info for Google, phone layout, AI crawlers (robots.txt plus a test visit as each bot), the sitemap and photo sizes.
- **Results on the page, report by email.** The visitor watches the four steps finish, sees the score and every row, and gets the full report by email with a "Book a call" button. A copy goes to the team with the visitor's contact details.
- **Site checks inbox** under Scout → Business, with the report, Send report, Reply by email and a Followed up box. The sidebar counts checks nobody has followed up on.
- **Review before sending** (off by default) holds each report until someone clicks Send report.
- **Built to be safe to leave on a public page:** a honeypot, limits per visitor, per email and per day, and every request goes through WordPress's safe HTTP calls, which refuse private and local addresses.
